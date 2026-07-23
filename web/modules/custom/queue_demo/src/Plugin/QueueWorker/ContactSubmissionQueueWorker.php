<?php

declare(strict_types=1);

namespace Drupal\queue_demo\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\queue_demo\Exception\CrmTemporarilyUnavailableException;
use Drupal\queue_demo\Service\CRMServiceInterface;
use Drupal\queue_demo\Service\EmailService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued contact submissions in the background.
 *
 * The plugin ID doubles as the queue name: whatever the form pushes into
 * queue 'queue_demo_contact_queue', this worker consumes.
 *
 * `cron: ['time' => 30]` registers this worker with core Cron: on every
 * cron run, Cron claims items from this queue and calls processItem() on
 * each, for AT MOST ~30 seconds, then moves on. Unfinished items simply
 * wait for the next run.
 *
 * Exception contract inside processItem():
 * - return normally  → Cron deletes the item (done forever).
 * - RequeueException → item is released and retried IN THE SAME cron run.
 * - SuspendQueueException → this item is released and the WHOLE queue is
 *   skipped until next cron (use when the failure affects every item,
 *   e.g. the CRM is down — no point hammering it 500 times).
 * - any other \Exception → logged by Cron, item released, retried on the
 *   NEXT cron run.
 */
#[QueueWorker(
  id: 'queue_demo_contact_queue',
  title: new TranslatableMarkup('Contact submission processor'),
  cron: ['time' => 30],
)]
final class ContactSubmissionQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EmailService $emailService,
    private readonly CRMServiceInterface $crmService,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * Plugins are instantiated by the plugin manager, not the container, so
   * DI happens through ContainerFactoryPluginInterface::create().
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('queue_demo.email_service'),
      $container->get('queue_demo.crm_service'),
      $container->get('logger.channel.queue_demo'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param array{name: string, email: string, message: string, submitted: int} $data
   *   The payload created in ContactForm::submitForm().
   */
  public function processItem($data): void {
    // Defensive: queue payloads are plain serialized data, never trust shape.
    foreach (['name', 'email', 'message'] as $required_key) {
      if (empty($data[$required_key]) || !is_string($data[$required_key])) {
        // Malformed item: retrying will NEVER fix it, so do not requeue.
        // Log loudly and return normally so Cron deletes it (in production,
        // push it to a dead-letter queue instead — see README).
        $this->logger->error('Queue failed: dropping malformed item, missing key %key. Payload: @payload', [
          '%key' => $required_key,
          '@payload' => json_encode($data),
        ]);
        return;
      }
    }

    $this->logger->info('Queue started: processing submission from @email.', [
      '@email' => $data['email'],
    ]);

    try {
      // Background action 1: confirmation email.
      $this->emailService->sendContactConfirmation($data['name'], $data['email'], $data['message']);

      // Background action 2: push the lead to the CRM.
      $lead_id = $this->crmService->saveLead($data['name'], $data['email'], $data['message']);
      $this->logger->info('CRM updated: lead @lead_id created for @email.', [
        '@lead_id' => $lead_id,
        '@email' => $data['email'],
      ]);

      $this->logger->info('Queue completed: submission from @email fully processed.', [
        '@email' => $data['email'],
      ]);
    }
    catch (CrmTemporarilyUnavailableException $e) {
      // TRANSIENT failure — the item is fine, the CRM is not. Requeue so it
      // is retried. RequeueException retries within the same cron run; if
      // the outage likely affects all items, SuspendQueueException would be
      // the better choice — shown here as the guarded alternative.
      $this->logger->warning('Queue failed (transient): @message — item requeued.', [
        '@message' => $e->getMessage(),
      ]);
      throw new RequeueException($e->getMessage(), 0, $e);
    }
    catch (\Exception $e) {
      // Unknown failure. Log with full context; rethrowing (without
      // Requeue/Suspend) releases the item so the NEXT cron run retries it.
      $this->logger->error('Queue failed: @message (submission from @email).', [
        '@message' => $e->getMessage(),
        '@email' => $data['email'],
      ]);
      throw $e;
    }
  }

}
