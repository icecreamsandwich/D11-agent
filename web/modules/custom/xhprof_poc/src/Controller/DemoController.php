<?php

declare(strict_types=1);

namespace Drupal\xhprof_poc\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\xhprof_poc\Profiler\ProfilerService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A deliberately heavy page split into instrumented sections.
 */
final class DemoController extends ControllerBase {

  public function __construct(
    private readonly ProfilerService $profiler,
    private readonly Connection $database,
    private readonly RendererInterface $renderer,
    private readonly CacheBackendInterface $cacheBackend,
    private readonly KillSwitch $killSwitch,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xhprof_poc.profiler'),
      $container->get('database'),
      $container->get('renderer'),
      $container->get('cache.default'),
      $container->get('page_cache_kill_switch'),
      $container->get('logger.factory')->get('xhprof_poc'),
    );
  }

  /**
   * Runs one workload step with entry/exit/failure logging.
   */
  private function step(string $name, string $label, callable $fn): string {
    $this->logger->info('Step "@name" starting.', ['@name' => $name]);
    $t0 = microtime(TRUE);
    try {
      $result = $this->profiler->measure($name, $label, $fn);
      $this->logger->info('Step "@name" finished in @ms ms: @result', [
        '@name' => $name,
        '@ms' => round((microtime(TRUE) - $t0) * 1000, 1),
        '@result' => $result,
      ]);
      return $result;
    }
    catch (\Throwable $e) {
      $this->logger->error('Step "@name" FAILED after @ms ms: @type: @message (@file:@line)', [
        '@name' => $name,
        '@ms' => round((microtime(TRUE) - $t0) * 1000, 1),
        '@type' => get_class($e),
        '@message' => $e->getMessage(),
        '@file' => $e->getFile(),
        '@line' => $e->getLine(),
      ]);
      throw $e;
    }
  }

  /**
   * Runs five workloads, each wrapped in a profiler section.
   */
  public function demo(): array {
    $this->logger->info('Demo page hit. Profiler active: @active. XHProf available: @xhprof.', [
      '@active' => $this->profiler->isActive() ? 'yes' : 'no',
      '@xhprof' => $this->profiler->isXhprofAvailable() ? 'yes' : 'no',
    ]);

    // Never cache this page: every request must execute the workloads.
    $this->killSwitch->trigger();
    $p = $this->profiler;

    $results = [];
    $results['Database'] = $this->step(
      'database', 'Database: 120 queries + entity query',
      fn(): string => $this->databaseWork()
    );
    $results['Computation'] = $this->step(
      'computation', 'CPU: prime sieve + 40k SHA-256 + bcrypt',
      fn(): string => $this->computationWork()
    );
    $results['External API'] = $this->step(
      'external_api', 'Simulated external API call (300 ms latency)',
      fn(): string => $this->apiWork()
    );
    $results['Cache'] = $this->step(
      'cache_ops', 'Cache: 200 writes + 200 reads',
      fn(): string => $this->cacheWork()
    );
    $table_markup = $this->step(
      'render_heavy', 'Render: 600-row table via renderer',
      fn(): string => $this->renderWork()
    );
    $this->logger->info('All 5 demo workloads completed; building page render array.');

    // Live per-section summary (sections are already stopped at this point).
    $rows = [];
    foreach ($p->getSections() as $s) {
      $rows[] = [
        $s['label'],
        $s['offset_ms'] . ' ms',
        $s['duration_ms'] . ' ms',
        round($s['memory_delta'] / 1024, 1) . ' KB',
      ];
    }

    $result_items = [];
    foreach ($results as $title => $text) {
      $result_items[] = ['#markup' => "<strong>$title:</strong> " . htmlspecialchars($text)];
    }

    return [
      '#cache' => ['max-age' => 0],
      '#attached' => ['library' => ['xhprof_poc/dashboard']],
      'intro' => [
        '#markup' => '<p>' . $this->t('This page just executed five heavy workloads. Each was wrapped in a profiler section; the full run (including XHProf function data when the extension is enabled) is saved after the response and visible on the dashboard.') . '</p>',
      ],
      'xhprof_status' => [
        '#markup' => '<p class="xp-status ' . ($p->isXhprofAvailable() ? 'xp-ok' : 'xp-warn') . '">'
        . ($p->isXhprofAvailable()
          ? $this->t('XHProf extension: enabled — function-level data is being captured.')
          : $this->t('XHProf extension: NOT loaded — only section timers captured. Run "ddev xhprof on" to enable it.'))
        . '</p>',
      ],
      'summary' => [
        '#type' => 'table',
        '#caption' => $this->t('Section timings for this request'),
        '#header' => [
          $this->t('Section'),
          $this->t('Start offset'),
          $this->t('Duration'),
          $this->t('Memory delta'),
        ],
        '#rows' => $rows,
      ],
      'results' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Workload results'),
        '#items' => $result_items,
      ],
      'dashboard_link' => [
        '#type' => 'link',
        '#title' => $this->t('Open profiler dashboard →'),
        '#url' => Url::fromRoute('xhprof_poc.dashboard'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      'rendered_table' => [
        '#type' => 'details',
        '#title' => $this->t('Render-heavy output (600-row table)'),
        '#open' => FALSE,
        'table' => ['#markup' => $table_markup],
      ],
    ];
  }

  /**
   * Workload: many small database queries plus an entity query.
   */
  private function databaseWork(): string {
    $count = 0;
    for ($i = 0; $i < 60; $i++) {
      $count = (int) $this->database
        ->query('SELECT COUNT(*) FROM {users_field_data}')
        ->fetchField();
      $this->database
        ->query('SELECT uid, name FROM {users_field_data} ORDER BY RAND() LIMIT 5')
        ->fetchAll();
    }
    $nids = $this->entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->range(0, 20)
      ->execute();
    return sprintf('%d users in system, %d nodes fetched, 120 raw queries executed.', $count, count($nids));
  }

  /**
   * Workload: pure CPU (sieve of Eratosthenes, hashing, bcrypt).
   */
  private function computationWork(): string {
    $limit = 200000;
    $sieve = array_fill(0, $limit + 1, TRUE);
    for ($i = 2; $i * $i <= $limit; $i++) {
      if ($sieve[$i]) {
        for ($j = $i * $i; $j <= $limit; $j += $i) {
          $sieve[$j] = FALSE;
        }
      }
    }
    $primes = 0;
    for ($i = 2; $i <= $limit; $i++) {
      if ($sieve[$i]) {
        $primes++;
      }
    }

    $hash = 'xhprof_poc';
    for ($i = 0; $i < 40000; $i++) {
      $hash = hash('sha256', $hash . $i);
    }
    password_hash('xhprof_poc', PASSWORD_BCRYPT, ['cost' => 11]);

    return sprintf('%d primes below %d, 40k SHA-256 rounds, 1 bcrypt hash.', $primes, $limit);
  }

  /**
   * Workload: simulated remote HTTP call latency.
   */
  private function apiWork(): string {
    usleep(300000);
    return 'Slept 300 ms simulating a remote HTTP call.';
  }

  /**
   * Workload: cache backend churn.
   */
  private function cacheWork(): string {
    for ($i = 0; $i < 200; $i++) {
      $this->cacheBackend->set('xhprof_poc:' . $i, str_repeat(md5((string) $i), 20));
    }
    $hits = 0;
    for ($i = 0; $i < 200; $i++) {
      if ($this->cacheBackend->get('xhprof_poc:' . $i)) {
        $hits++;
      }
    }
    return sprintf('%d/200 cache hits after 200 writes.', $hits);
  }

  /**
   * Workload: build and render a large render array.
   */
  private function renderWork(): string {
    $rows = [];
    for ($i = 1; $i <= 600; $i++) {
      $rows[] = [
        'Row ' . $i,
        md5((string) $i),
        sha1((string) ($i * 7)),
        number_format($i * 3.14159, 4),
      ];
    }
    $build = [
      '#type' => 'table',
      '#header' => ['#', 'MD5', 'SHA1', 'Value'],
      '#rows' => $rows,
    ];
    return (string) $this->renderer->renderInIsolation($build);
  }

}
