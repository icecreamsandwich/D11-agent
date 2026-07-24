# XHProf Profiling POC (xhprof_poc)

POC for profiling Drupal page rendering: per-section wall-time/memory timers plus
XHProf function-level data, with a built-in dashboard UI.

## What it does

- **Heavy demo page** at `/profiler-poc/demo` — runs 5 workloads (DB queries,
  CPU computation, simulated 300 ms API call, cache churn, 600-row render),
  each wrapped in a named profiler section.
- **Profiler service** (`xhprof_poc.profiler`) — starts XHProf right after
  routing (event subscriber), records section timings, and saves the run on
  `kernel.terminate` (after the response is sent, so profiling storage doesn't
  slow the page).
- **Dashboard** at `/admin/reports/xhprof-poc` (Reports menu) — run list,
  summary cards, a section waterfall chart, and a sortable table of the top 50
  functions by inclusive wall time (calls, incl/excl wall, CPU, memory).
- **Profile any page**: append `?xhprof_poc=1` to any uncached page URL.

## Setup (DDEV)

```bash
# 1. Enable the XHProf extension in the web container
ddev xhprof on

# 2. Enable the module
ddev drush en xhprof_poc -y

# 3. Grant the permission (or browse as user 1)
#    "Access XHProf POC demo page and profiler reports"
ddev drush role:perm:add administrator 'access xhprof poc'

# 4. Trigger a run, then inspect it
open https://d-11.ddev.site/profiler-poc/demo
open https://d-11.ddev.site/admin/reports/xhprof-poc
```

> **Why not /xhprof-poc/demo?** `ddev xhprof on` adds an nginx
> `location ^~ /xhprof` block for its own viewer UI. That prefix also matches
> any Drupal path starting with `/xhprof`, so nginx serves a raw 404 instead
> of passing the request to Drupal. The demo route therefore lives at
> `/profiler-poc/demo`. Every demo step is also logged to the `xhprof_poc`
> channel (`ddev drush watchdog:show --type=xhprof_poc`).

Without `ddev xhprof on` everything still works, but only section timers are
captured (no function-level table).

## Notes / caveats

- **DDEV's own XHProf UI**: `ddev xhprof on` also enables DDEV's
  auto-prepend, which profiles every request and serves its own callgraph UI
  at `/xhprof`. This module restarts XHProf collection after routing, so
  DDEV's UI will show mostly-empty runs for profiled pages while this module's
  dashboard has the real data. Both can coexist; just read the right UI.
  To disable DDEV's prepend but keep the extension, empty
  `.ddev/xhprof/xhprof_prepend.php` and `ddev restart`.
- Profiling starts **after routing** (subscriber priority 28), so bootstrap
  isn't inside the XHProf capture — the dashboard shows it separately as
  "Bootstrap before profiler" using `REQUEST_TIME_FLOAT`.
- Anonymous **page cache** serves cached pages before the kernel events run,
  so `?xhprof_poc=1` on a fully cached anonymous page records nothing useful.
  Profile while logged in, or use the demo page (it kill-switches caching).
- Runs are stored in the key-value store; only the **25 most recent** are kept.
- This is a POC — don't enable on production (profiling overhead, and the
  `?xhprof_poc=1` flag lets any permitted user trigger profiling).

## Using the profiler in your own code

```php
$profiler = \Drupal::service('xhprof_poc.profiler');

// Wrap any block of work in a named section:
$result = $profiler->measure('my_section', 'Building product listing', function () {
  // ... heavy work ...
  return $something;
});

// Or manually:
$profiler->start('teaser_render', 'Rendering teasers');
// ...
$profiler->stop('teaser_render');
```

Sections only record when a run is active (demo route or `?xhprof_poc=1`),
so instrumentation is safe to leave in place.

## File map

| File | Purpose |
|---|---|
| `src/Profiler/ProfilerService.php` | XHProf capture, section timers, run storage/aggregation |
| `src/EventSubscriber/ProfilerSubscriber.php` | Starts run post-routing, saves on terminate |
| `src/Controller/DemoController.php` | The 5-workload heavy demo page |
| `src/Controller/DashboardController.php` | Run list + detail UI (waterfall, tables) |
| `css/dashboard.css`, `js/dashboard.js` | Dashboard styling + client-side table sorting |
