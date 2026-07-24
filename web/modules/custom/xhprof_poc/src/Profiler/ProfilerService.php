<?php

declare(strict_types=1);

namespace Drupal\xhprof_poc\Profiler;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Captures per-section timings plus XHProf function data for one request.
 *
 * Lifecycle: startRun() (event subscriber) -> start()/stop()/measure()
 * (controllers/services) -> endRun() (kernel.terminate), which persists the
 * run to the key-value store for the dashboard.
 */
class ProfilerService {

  public const COLLECTION = 'xhprof_poc_runs';
  public const INDEX_KEY = 'xhprof_poc.run_index';
  public const MAX_RUNS = 25;
  public const MAX_FUNCTIONS = 50;

  /**
   * Whether a profiling run is currently active.
   *
   * @var bool
   */
  protected bool $active = FALSE;

  /**
   * Whether the XHProf extension is currently collecting data.
   *
   * @var bool
   */
  protected bool $xhprofRunning = FALSE;

  /**
   * Microtime at which the current run started.
   *
   * @var float
   */
  protected float $runStart = 0.0;

  /**
   * Bootstrap time in milliseconds for the current run.
   *
   * @var float
   */
  protected float $bootstrapMs = 0.0;

  /**
   * Request URI of the current run.
   *
   * @var string
   */
  protected string $uri = '';

  /**
   * Completed sections.
   *
   * @var array[]
   */
  protected array $sections = [];

  /**
   * Currently open (started, not stopped) sections, keyed by name.
   *
   * @var array[]
   */
  protected array $open = [];

  public function __construct(
    protected KeyValueFactoryInterface $keyValueFactory,
    protected StateInterface $state,
    protected TimeInterface $time,
  ) {}

  /**
   * Whether the XHProf extension is loaded (ddev xhprof on).
   */
  public function isXhprofAvailable(): bool {
    return function_exists('xhprof_enable') && function_exists('xhprof_disable');
  }

  /**
   * Whether a profiling run is currently active.
   */
  public function isActive(): bool {
    return $this->active;
  }

  /**
   * Starts a profiling run for the current request.
   *
   * @param string $uri
   *   The request URI, stored with the run.
   * @param float|null $request_start
   *   REQUEST_TIME_FLOAT, used to report bootstrap time before profiling
   *   started.
   */
  public function startRun(string $uri, ?float $request_start = NULL): void {
    if ($this->active) {
      return;
    }
    $this->active = TRUE;
    $this->uri = $uri;
    $this->runStart = microtime(TRUE);
    $this->bootstrapMs = $request_start ? ($this->runStart - $request_start) * 1000 : 0.0;

    if ($this->isXhprofAvailable()) {
      $flags = XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY;
      if (defined('XHPROF_FLAGS_NO_BUILTINS')) {
        $flags |= XHPROF_FLAGS_NO_BUILTINS;
      }
      xhprof_enable($flags);
      $this->xhprofRunning = TRUE;
    }
  }

  /**
   * Opens a named timing section.
   */
  public function start(string $name, string $label = ''): void {
    if (!$this->active || isset($this->open[$name])) {
      return;
    }
    $this->open[$name] = [
      'label' => $label ?: $name,
      'start' => microtime(TRUE),
      'mem' => memory_get_usage(),
    ];
  }

  /**
   * Closes a named timing section and records its metrics.
   */
  public function stop(string $name): void {
    if (!isset($this->open[$name])) {
      return;
    }
    $o = $this->open[$name];
    unset($this->open[$name]);
    $now = microtime(TRUE);
    $this->sections[] = [
      'name' => $name,
      'label' => $o['label'],
      'offset_ms' => round(($o['start'] - $this->runStart) * 1000, 2),
      'duration_ms' => round(($now - $o['start']) * 1000, 2),
      'memory_delta' => memory_get_usage() - $o['mem'],
    ];
  }

  /**
   * Runs a callable inside a timed section and returns its result.
   */
  public function measure(string $name, string $label, callable $fn): mixed {
    $this->start($name, $label);
    try {
      return $fn();
    }
    finally {
      $this->stop($name);
    }
  }

  /**
   * Sections recorded so far (useful to print on the profiled page itself).
   */
  public function getSections(): array {
    return $this->sections;
  }

  /**
   * Ends the run, aggregates XHProf data and persists everything.
   *
   * @return string|null
   *   The stored run ID, or NULL if no run was active.
   */
  public function endRun(): ?string {
    if (!$this->active) {
      return NULL;
    }
    $this->active = FALSE;

    // Close any sections left open.
    foreach (array_keys($this->open) as $name) {
      $this->stop($name);
    }
    $total_ms = round((microtime(TRUE) - $this->runStart) * 1000, 2);

    $xhprof = ['enabled' => FALSE, 'total_functions' => 0, 'top' => []];
    if ($this->xhprofRunning) {
      $data = xhprof_disable();
      $this->xhprofRunning = FALSE;
      if (is_array($data)) {
        $xhprof = $this->aggregate($data);
      }
    }

    $id = date('Ymd-His') . '-' . substr(uniqid(), -5);
    $run = [
      'id' => $id,
      'uri' => $this->uri,
      'timestamp' => $this->time->getRequestTime(),
      'bootstrap_ms' => round($this->bootstrapMs, 2),
      'total_ms' => $total_ms,
      'peak_memory' => memory_get_peak_usage(TRUE),
      'sections' => $this->sections,
      'xhprof' => $xhprof,
    ];

    $store = $this->keyValueFactory->get(self::COLLECTION);
    $store->set($id, $run);

    // Maintain a rolling index of the most recent runs.
    $index = $this->state->get(self::INDEX_KEY, []);
    array_unshift($index, $id);
    foreach (array_slice($index, self::MAX_RUNS) as $old) {
      $store->delete($old);
    }
    $this->state->set(self::INDEX_KEY, array_slice($index, 0, self::MAX_RUNS));

    return $id;
  }

  /**
   * Returns stored runs, newest first.
   */
  public function getRuns(): array {
    $store = $this->keyValueFactory->get(self::COLLECTION);
    $runs = [];
    foreach ($this->state->get(self::INDEX_KEY, []) as $id) {
      if ($run = $store->get($id)) {
        $runs[] = $run;
      }
    }
    return $runs;
  }

  /**
   * Loads one stored run by ID.
   */
  public function getRun(string $id): ?array {
    return $this->keyValueFactory->get(self::COLLECTION)->get($id);
  }

  /**
   * Aggregates raw XHProf "parent==>child" data into per-function totals.
   */
  protected function aggregate(array $data): array {
    $funcs = [];
    foreach ($data as $key => $m) {
      $child = str_contains($key, '==>') ? explode('==>', $key, 2)[1] : $key;
      if (!isset($funcs[$child])) {
        $funcs[$child] = [
          'fn' => $child,
          'calls' => 0,
          'wt' => 0,
          'excl_wt' => 0,
          'cpu' => 0,
          'mu' => 0,
        ];
      }
      $funcs[$child]['calls'] += $m['ct'];
      $funcs[$child]['wt'] += $m['wt'];
      $funcs[$child]['excl_wt'] += $m['wt'];
      $funcs[$child]['cpu'] += $m['cpu'] ?? 0;
      $funcs[$child]['mu'] += $m['mu'] ?? 0;
    }

    // Exclusive wall time = inclusive minus time spent in direct children.
    foreach ($data as $key => $m) {
      if (str_contains($key, '==>')) {
        $parent = explode('==>', $key, 2)[0];
        if (isset($funcs[$parent])) {
          $funcs[$parent]['excl_wt'] -= $m['wt'];
        }
      }
    }

    usort($funcs, fn(array $a, array $b) => $b['wt'] <=> $a['wt']);
    $top = array_slice(array_values($funcs), 0, self::MAX_FUNCTIONS);
    foreach ($top as &$f) {
      $f['wt_ms'] = round($f['wt'] / 1000, 2);
      $f['excl_wt_ms'] = round(max($f['excl_wt'], 0) / 1000, 2);
      $f['cpu_ms'] = round($f['cpu'] / 1000, 2);
      $f['mu_kb'] = round($f['mu'] / 1024, 1);
      unset($f['wt'], $f['excl_wt'], $f['cpu'], $f['mu']);
    }
    unset($f);

    return [
      'enabled' => TRUE,
      'total_functions' => count($funcs),
      'top' => $top,
    ];
  }

}
