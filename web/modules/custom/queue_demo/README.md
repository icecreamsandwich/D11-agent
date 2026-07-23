# Queue Demo — Drupal 11 Queue API POC

Contact form → queue item → instant success message → cron/drush processes the item later → confirmation email + mock CRM lead.

## Folder structure

```
queue_demo/
├── queue_demo.info.yml
├── queue_demo.module                 # hook_mail() only (Drupal requires the hook)
├── queue_demo.routing.yml            # /queue-demo/contact
├── queue_demo.links.menu.yml
├── queue_demo.permissions.yml
├── queue_demo.services.yml           # DI wiring + logger channel
├── README.md
└── src/
    ├── Exception/
    │   └── CrmTemporarilyUnavailableException.php   # transient vs permanent failures
    ├── Form/
    │   └── ContactForm.php           # validates, enqueues, thanks the user
    ├── Plugin/QueueWorker/
    │   └── ContactSubmissionQueueWorker.php          # cron-driven consumer
    └── Service/
        ├── CRMServiceInterface.php   # swap point for real Salesforce later
        ├── EmailService.php          # Mail Manager wrapper
        └── MockCRMService.php        # logs instead of calling an API
```

## Execution flow

```
User
 ↓  fills Name / Email / Message
Contact Form  (/queue-demo/contact)
 ↓  validateForm() → submitForm()
Queue Item Created            ← INSERT into {queue} table, ~1 ms
 ↓
Success Response              ← "Thank you. Your request is being processed."
 ↓  (user is gone; work waits)
Cron  (or: drush queue:run queue_demo_contact_queue)
 ↓  claims items, max 30 s (cron = {"time" = 30})
Queue Worker  (ContactSubmissionQueueWorker::processItem)
 ↓
Email Service                 ← Mail Manager → hook_mail('contact_request')
 ↓
CRM Service                   ← MockCRMService::saveLead() → log entry
 ↓
Completed                     ← item deleted from {queue}
```

Failure branch:

```
processItem()
 ├─ CrmTemporarilyUnavailableException → RequeueException → retried same cron run
 ├─ other \Exception → item released → retried NEXT cron run
 └─ malformed payload → logged + dropped (never retryable)
```

## Setup & testing

```bash
ddev drush en queue_demo -y
ddev drush role:perm:add anonymous 'submit queue demo contact form'   # or authenticated
ddev drush cr
```

1. Visit `https://d-11.ddev.site/queue-demo/contact`, submit the form.
2. Confirm the item is queued:
   ```bash
   ddev drush queue:list
   #  queue_demo_contact_queue   1   Drupal\Core\Queue\DatabaseQueue
   ddev drush sql:query "SELECT item_id, name, expire, created FROM queue"
   ```
3. Process it either way:
   ```bash
   ddev drush queue:run queue_demo_contact_queue   # immediate, this queue only
   ddev drush cron                                 # full cron run (all workers)
   ```
4. Check the logs: `/admin/reports/dblog` (filter type = `queue_demo`) or
   ```bash
   ddev drush watchdog:show --type=queue_demo
   ```
5. **Test the retry path**: submit the form with `FAIL_CRM` anywhere in the
   message. MockCRMService throws a transient exception, the worker logs a
   warning and throws `RequeueException`. (Note: with `queue:run`, an item that
   always fails will loop — Ctrl-C, or edit the message logic. Under cron the
   30 s time budget bounds it.)
6. Emails in DDEV are caught by Mailpit: `ddev launch -m` (nothing leaves the box).

## Expected logs (type = queue_demo)

```
Queue item created (item 42) for jane@example.com in queue queue_demo_contact_queue.
Queue started: processing submission from jane@example.com.
Email sent to jane@example.com (subject: Contact Request Received).
Saving Lead to CRM — ID: LEAD-8F3A21C4 | Name: Jane | Email: jane@example.com | Message: ...
CRM updated: lead LEAD-8F3A21C4 created for jane@example.com.
Queue completed: submission from jane@example.com fully processed.
```

Failure case adds: `Queue failed (transient): Mock CRM outage ... — item requeued.`

## How it works

### Why queue instead of doing everything in submitForm()?

The form response time no longer depends on the mail server or CRM API — the user waits for one database INSERT instead of two network round-trips (an SMTP timeout alone can be 30 s). Failures are decoupled: if the CRM is down, the submission is safely stored and retried later instead of showing the user an error. It also scales (10,000 submissions enqueue fine; the workers drain at their own pace, throttled by `time`) and keeps responsibilities clean — the form collects data, the worker processes it.

### How Queue API stores items

Core's default backend is `DatabaseQueue`, one table: `{queue}`.

| column | meaning |
|---|---|
| `item_id` | auto-increment ID |
| `name` | queue name (`queue_demo_contact_queue`) |
| `data` | the item payload, PHP-serialized (our array of name/email/message) |
| `expire` | `0` = available; a future timestamp = claimed by a worker (lease) |
| `created` | when the item was enqueued |

`createItem()` = INSERT. `claimItem($lease)` = atomically pick the oldest item with `expire = 0` and set `expire = now + lease` so no other worker grabs it. `deleteItem()` on success; `releaseItem()` (sets `expire = 0`) on failure. If a worker crashes mid-item and never calls either, the lease simply expires and the item becomes claimable again — that is the crash-safety mechanism. The backend is swappable via `$settings['queue_service_queue_demo_contact_queue']` (e.g. Redis) with zero code changes.

### How cron processes queue workers

Core's `Cron` service, after invoking `hook_cron()`, asks the QueueWorker plugin manager for every worker whose definition includes `cron`. For each: it grabs the queue, then loops `claimItem()` → `processItem()` → `deleteItem()` until the queue is empty **or** the `time` budget (30 s for us) is spent. Leftover items just wait for the next cron run. So `cron: ['time' => 30]` means "spend at most ~30 seconds per cron run on this queue", protecting cron from one fat queue starving everything else.

### How retries work

There is no retry counter in core — retry is an emergent property of the lease cycle: a failed item is *released* (`expire = 0`) and picked up again on a later run. Three levers control it: throw nothing → done; throw `RequeueException` → immediate retry (same run); throw any other exception → retry next cron run; throw `SuspendQueueException` → skip this *entire queue* until next cron (and since 10.1, `DelayedRequeueException` → retry after a delay on backends that support it). If you need "max 3 attempts then give up", put an `attempts` counter inside the payload and re-enqueue a copy — see production notes.

### When to use RequeueException

Only for **transient** failures where immediate retry is plausible: rate-limit responses, momentary network blips, a lock held by another process. Use plain exceptions when "later" (next cron) is the right retry moment, `SuspendQueueException` when the failure will hit every item (CRM fully down — don't hammer it), and **no retry at all** for permanent failures (malformed data, validation errors) — retrying those just fills logs forever.

### What happens if cron stops?

Nothing is lost — items accumulate in the `{queue}` table (visible via `drush queue:list`), users keep getting instant success responses, and when cron resumes the backlog drains at ≤30 s per run. That's the durability win over "fire and forget" approaches. Risks are operational: stale confirmation emails, a growing table. Mitigate by monitoring queue depth and alerting when items are older than N minutes.

## Dependency injection map

```
ContactForm::create()                    ContactSubmissionQueueWorker::create()
 ├─ queue (QueueFactory)                  ├─ queue_demo.email_service (EmailService)
 ├─ logger.channel.queue_demo             │    ├─ plugin.manager.mail (MailManagerInterface)
 ├─ datetime.time (TimeInterface)         │    ├─ language_manager
 └─ messenger (MessengerInterface)        │    └─ logger.channel.queue_demo
                                          ├─ queue_demo.crm_service (CRMServiceInterface ← MockCRMService)
                                          │    └─ logger.channel.queue_demo
                                          └─ logger.channel.queue_demo
```

No `\Drupal::service()` anywhere in classes. Forms get the container in `create()` (they're resolved by the class resolver); plugins implement `ContainerFactoryPluginInterface` because the plugin manager, not the container, instantiates them; plain services are wired in `queue_demo.services.yml`. The worker depends on `CRMServiceInterface`, so going live with Salesforce = write `SalesforceCRMService implements CRMServiceInterface`, change one `class:` line in services.yml.

## Interview questions this project answers

1. Why not send the email directly in `submitForm()`? (latency, failure isolation, scalability)
2. Difference between `RequeueException`, `SuspendQueueException`, `DelayedRequeueException`, and a plain exception in `processItem()`?
3. How does `claimItem()` prevent two workers processing the same item? (atomic UPDATE on `expire` — the lease)
4. What happens to an item if PHP fatals mid-`processItem()`? (lease expires, item auto-recovers)
5. Where does `cron = {"time" = 30}` apply, and what does it limit? (per-worker time budget per cron run, not per item)
6. Why must a QueueWorker implement `ContainerFactoryPluginInterface` for DI? (plugin manager instantiates plugins, not the container)
7. Why `logger.channel.queue_demo` instead of injecting `logger.factory`? (channel is pre-bound, cheaper, and the class depends only on PSR `LoggerInterface`)
8. How would you swap DatabaseQueue for Redis? (`queue_service_{name}` / `queue_default` settings — no code change)
9. Why keep the payload small (IDs, scalars) instead of serializing entities into the queue?
10. QueueWorker vs Batch API vs hook_cron — when do you pick which? (queue: durable async units; batch: user-facing long operation with progress; hook_cron: small periodic housekeeping)

## Production improvements

For a real deployment: store submissions as entities first and queue only the ID (small payloads, admin UI, audit trail — the current POC queues the full payload for simplicity); add an `attempts` counter with a max-retries + dead-letter queue so poison items can't loop forever; run queues off-request with `drush queue:run` via a systemd timer or a dedicated worker container instead of relying on page-triggered cron; swap the backend to Redis for throughput; replace MockCRMService with a real client behind the same interface using Guzzle + circuit breaker; make the notification address/subjects configurable (ConfigFormBase + schema); add Kernel tests for the worker (`\Drupal::queue()` in tests is fine) and a functional test for the form; and wire queue-depth monitoring (e.g. a `queue:list` check in your APM) with alerting on stale items.
