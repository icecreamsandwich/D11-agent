<?php

declare(strict_types=1);

namespace Drupal\d11_performance_optimizer\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\d11_performance_optimizer\Service\CacheAnalyzerService;
use Drupal\d11_performance_optimizer\Service\CodingStandardsValidatorService;
use Drupal\d11_performance_optimizer\Service\DatabaseQueryMonitorService;
use Drupal\d11_performance_optimizer\Service\PerformanceAnalyzerService;
use Drupal\d11_performance_optimizer\Service\PerformanceLoggerService;
use Drupal\d11_performance_optimizer\Service\RecommendationEngineService;
use Drupal\d11_performance_optimizer\Service\SEOOptimizationService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provides the performance optimizer admin dashboard pages.
 */
final class AdminDashboardController extends ControllerBase {

  public function __construct(
    private readonly PerformanceAnalyzerService $performanceAnalyzer,
    private readonly DatabaseQueryMonitorService $queryMonitor,
    private readonly CacheAnalyzerService $cacheAnalyzer,
    private readonly SEOOptimizationService $seoService,
    private readonly CodingStandardsValidatorService $codingStandards,
    private readonly RecommendationEngineService $recommendations,
    private readonly PerformanceLoggerService $performanceLogger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('d11_performance_optimizer.performance_analyzer'),
      $container->get('d11_performance_optimizer.database_query_monitor'),
      $container->get('d11_performance_optimizer.cache_analyzer'),
      $container->get('d11_performance_optimizer.seo_optimization'),
      $container->get('d11_performance_optimizer.coding_standards_validator'),
      $container->get('d11_performance_optimizer.recommendation_engine'),
      $container->get('d11_performance_optimizer.performance_logger'),
    );
  }

  /**
   * Main overview dashboard page.
   *
   * @return array<string, mixed>
   */
  public function overview(): array {
    $metrics = $this->performanceAnalyzer->getAggregatedMetrics(24);
    $cacheReport = $this->cacheAnalyzer->analyze();
    $recs = $this->recommendations->generate();
    $logSummary = $this->performanceLogger->getLogSummary();

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['d11-perf-dashboard']],
    ];

    $build['tabs'] = $this->buildNavigation();

    $build['overview'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Overview — Last 24 Hours'),
      'cards' => $this->buildMetricCards($metrics),
    ];

    $build['cache_status'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Cache Module Status'),
      'modules' => $this->buildCacheModuleStatus($cacheReport),
    ];

    if (!empty($recs)) {
      $build['recommendations'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Recommendations (@count)', ['@count' => count($recs)]),
        'list' => $this->buildRecommendationsTable($recs),
      ];
    }

    if (!empty($logSummary)) {
      $build['log_summary'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Performance Log Summary'),
        'table' => $this->buildLogSummaryTable($logSummary),
      ];
    }

    $clearUrl = Url::fromRoute('d11_performance_optimizer.clear_metrics', [], [
      'query' => ['token' => \Drupal::getContainer()->get('csrf_token')->get('d11_performance_optimizer.clear_metrics')],
    ]);

    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['style' => 'margin-top:1em;'],
      'clear' => [
        '#type' => 'link',
        '#title' => $this->t('Clear All Metrics & Logs'),
        '#url' => $clearUrl,
        '#attributes' => ['class' => ['button', 'button--danger']],
      ],
    ];

    $build['#attached']['library'][] = 'd11_performance_optimizer/dashboard';

    return $build;
  }

  /**
   * Detailed performance metrics page.
   *
   * @return array<string, mixed>
   */
  public function performance(): array {
    $recentMetrics = $this->performanceAnalyzer->getRecentMetrics(50);
    $aggregated = $this->performanceAnalyzer->getAggregatedMetrics(24);

    $header = [
      $this->t('Path'),
      $this->t('Load (ms)'),
      $this->t('Twig (ms)'),
      $this->t('Queries'),
      $this->t('Memory'),
      $this->t('Cache Hits'),
      $this->t('Cache Misses'),
      $this->t('Response'),
      $this->t('Recorded'),
    ];

    $rows = [];
    foreach ($recentMetrics as $row) {
      $isSlow = (float) $row->page_load_time > (float) $this->config('d11_performance_optimizer.settings')->get('slow_request_threshold');
      $rows[] = [
        ['data' => substr($row->path, 0, 60), 'title' => $row->path],
        ['data' => $this->formatMs((float) $row->page_load_time), 'class' => $isSlow ? ['color-error'] : []],
        $this->formatMs((float) $row->twig_render_time),
        (int) $row->db_query_count,
        $this->formatBytes((int) $row->memory_usage),
        (int) $row->render_cache_hits,
        (int) $row->render_cache_misses,
        $this->formatBytes((int) $row->response_size),
        \Drupal::service('date.formatter')->format((int) $row->timestamp, 'short'),
      ];
    }

    $build = [
      '#type' => 'container',
      'tabs' => $this->buildNavigation(),
    ];

    if (!empty($aggregated)) {
      $build['aggregated'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('24-Hour Averages'),
        'items' => [
          '#theme' => 'item_list',
          '#items' => [
            $this->t('Avg Page Load: @v ms', ['@v' => $aggregated['avg_page_load_time']]),
            $this->t('Avg Twig Render: @v ms', ['@v' => $aggregated['avg_twig_render_time']]),
            $this->t('Avg DB Queries: @v', ['@v' => $aggregated['avg_db_query_count']]),
            $this->t('Avg Memory: @v', ['@v' => $this->formatBytes($aggregated['avg_memory_usage'])]),
            $this->t('Cache Hit Ratio: @v%', ['@v' => round(($aggregated['cache_hit_ratio'] ?? 0) * 100, 1)]),
            $this->t('Slow Requests (24h): @v', ['@v' => $aggregated['slow_requests']]),
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No performance metrics recorded yet. Metrics are collected on frontend page requests (1 in @rate sampled).', [
        '@rate' => $this->config('d11_performance_optimizer.settings')->get('sampling_rate'),
      ]),
      '#caption' => $this->t('Most recent 50 sampled requests.'),
    ];

    return $build;
  }

  /**
   * Slow database queries page.
   *
   * @return array<string, mixed>
   */
  public function database(): array {
    $slowQueries = $this->queryMonitor->getRecentSlowQueries(25);
    $threshold = $this->config('d11_performance_optimizer.settings')->get('slow_query_threshold');

    $header = [
      $this->t('Query (truncated)'),
      $this->t('Exec Time (ms)'),
      $this->t('Occurrences'),
      $this->t('Path'),
      $this->t('Last Seen'),
    ];

    $rows = [];
    foreach ($slowQueries as $row) {
      $rows[] = [
        ['data' => substr($row->query, 0, 120), 'title' => $row->query],
        ['data' => $this->formatMs((float) $row->execution_time), 'class' => ['color-error']],
        (int) $row->occurrence_count,
        substr($row->path, 0, 60),
        \Drupal::service('date.formatter')->format((int) $row->timestamp, 'short'),
      ];
    }

    return [
      '#type' => 'container',
      'tabs' => $this->buildNavigation(),
      'threshold_note' => [
        '#markup' => '<p>' . $this->t('Showing queries exceeding the @ms ms threshold. Configure in <a href=":url">settings</a>.', [
          '@ms' => $threshold,
          ':url' => Url::fromRoute('d11_performance_optimizer.settings')->toString(),
        ]) . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No slow queries detected. Keep an eye on this after real traffic.'),
        '#caption' => $this->t('Slow queries are deduplicated by pattern; occurrence count tracks how often each pattern fires.'),
      ],
    ];
  }

  /**
   * Asset optimization status page.
   *
   * @return array<string, mixed>
   */
  public function assets(): array {
    $systemPerf = $this->config('system.performance');
    $modConfig = $this->config('d11_performance_optimizer.settings');

    $rows = [
      [$this->t('CSS Aggregation (Drupal core)'), $systemPerf->get('css.preprocess') ? '✅ Enabled' : '❌ Disabled'],
      [$this->t('JS Aggregation (Drupal core)'), $systemPerf->get('js.preprocess') ? '✅ Enabled' : '❌ Disabled'],
      [$this->t('JS Defer (this module)'), $modConfig->get('defer_javascript') ? '✅ Enabled' : '❌ Disabled'],
      [$this->t('Lazy-load JS (this module)'), $modConfig->get('lazy_load_javascript') ? '✅ Enabled' : '❌ Disabled'],
      [$this->t('Large Asset Threshold'), $this->formatBytes((int) $modConfig->get('large_asset_threshold'))],
      [$this->t('Critical CSS Threshold'), $this->formatBytes((int) $modConfig->get('critical_css_threshold'))],
    ];

    return [
      '#type' => 'container',
      'tabs' => $this->buildNavigation(),
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Setting'), $this->t('Status / Value')],
        '#rows' => $rows,
        '#caption' => $this->t('Asset optimization settings.'),
      ],
      'actions' => [
        '#type' => 'link',
        '#title' => $this->t('Configure Drupal core performance settings'),
        '#url' => Url::fromRoute('system.performance_settings'),
        '#attributes' => ['class' => ['button'], 'style' => 'margin-top:.5em; display:inline-block;'],
      ],
    ];
  }

  /**
   * SEO and performance logs page.
   *
   * @return array<string, mixed>
   */
  public function seo(): array {
    $logs = $this->performanceLogger->getRecentLogs(50);

    $header = [
      $this->t('Type'),
      $this->t('Severity'),
      $this->t('Path'),
      $this->t('Message'),
      $this->t('Time'),
    ];

    $rows = [];
    foreach ($logs as $log) {
      $severityClass = match ($log->severity) {
        'error' => 'color-error',
        'warning' => 'color-warning',
        default => '',
      };
      $rows[] = [
        $log->log_type,
        ['data' => $log->severity, 'class' => $severityClass ? [$severityClass] : []],
        substr($log->path, 0, 50),
        substr($log->message, 0, 120),
        \Drupal::service('date.formatter')->format((int) $log->timestamp, 'short'),
      ];
    }

    return [
      '#type' => 'container',
      'tabs' => $this->buildNavigation(),
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No performance or SEO log entries yet.'),
        '#caption' => $this->t('Last 50 performance and SEO log entries.'),
      ],
    ];
  }

  /**
   * Coding standards validation page.
   *
   * @return array<string, mixed>
   */
  public function codingStandards(): array {
    $config = $this->config('d11_performance_optimizer.settings');

    $build = [
      '#type' => 'container',
      'tabs' => $this->buildNavigation(),
    ];

    if (!$config->get('enable_coding_standards')) {
      $build['message'] = [
        '#markup' => '<div class="messages messages--warning"><p>' . $this->t(
          'Coding Standards validation is disabled. Enable it in <a href=":url">Performance Optimizer settings</a>.',
          [':url' => Url::fromRoute('d11_performance_optimizer.settings')->toString()]
        ) . '</p></div>',
      ];
      return $build;
    }

    $results = $this->codingStandards->validateCustomModules();

    if (empty($results)) {
      $build['message'] = [
        '#markup' => '<p>' . $this->t('✅ No custom modules found at /modules/custom, or no issues detected.') . '</p>',
      ];
      return $build;
    }

    $totalIssues = 0;
    foreach ($results as $moduleName => $fileIssues) {
      $rows = [];
      foreach ($fileIssues as $file => $issues) {
        if ($file === '_phpcs') {
          $rows[] = [['data' => $this->t('PHPCS Summary'), 'colspan' => 2], ['data' => $issues, 'colspan' => 1]];
          continue;
        }
        foreach ($issues as $issue) {
          $totalIssues++;
          $rows[] = [
            $file,
            $issue['severity'] ?? 'warning',
            $issue['message'] ?? '',
          ];
        }
      }

      if (!empty($rows)) {
        $build[$moduleName] = [
          '#type' => 'fieldset',
          '#title' => $this->t('Module: @name (@count issues)', [
            '@name' => $moduleName,
            '@count' => count($rows),
          ]),
          'table' => [
            '#type' => 'table',
            '#header' => [$this->t('File'), $this->t('Severity'), $this->t('Issue')],
            '#rows' => $rows,
          ],
        ];
      }
    }

    $build['summary'] = [
      '#markup' => '<p>' . $this->t('Total issues found: @count.', ['@count' => $totalIssues]) . '</p>',
      '#weight' => -10,
    ];

    return $build;
  }

  /**
   * Clears all stored metrics, logs, and slow queries.
   */
  public function clearMetrics(): RedirectResponse {
    $database = \Drupal::database();
    $database->truncate('d11_performance_metrics')->execute();
    $database->truncate('d11_performance_logs')->execute();
    $database->truncate('d11_slow_queries')->execute();

    $this->messenger()->addStatus($this->t('All performance metrics, logs, and slow query records have been cleared.'));
    return $this->redirect('d11_performance_optimizer.dashboard');
  }

  // ---------------------------------------------------------------------------
  // Private render helpers
  // ---------------------------------------------------------------------------

  /**
   * Builds dashboard navigation tabs.
   *
   * @return array<string, mixed>
   */
  private function buildNavigation(): array {
    $links = [
      ['route' => 'd11_performance_optimizer.dashboard', 'label' => $this->t('Overview')],
      ['route' => 'd11_performance_optimizer.dashboard.performance', 'label' => $this->t('Performance')],
      ['route' => 'd11_performance_optimizer.dashboard.database', 'label' => $this->t('Database')],
      ['route' => 'd11_performance_optimizer.dashboard.assets', 'label' => $this->t('Assets')],
      ['route' => 'd11_performance_optimizer.dashboard.seo', 'label' => $this->t('SEO / Logs')],
      ['route' => 'd11_performance_optimizer.dashboard.coding_standards', 'label' => $this->t('Coding Standards')],
    ];

    $items = [];
    foreach ($links as $link) {
      $items[] = [
        '#type' => 'link',
        '#title' => $link['label'],
        '#url' => Url::fromRoute($link['route']),
        '#attributes' => ['class' => ['button', 'button--small'], 'style' => 'margin-right:4px;'],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['style' => 'margin-bottom:1.2em;'],
      'links' => $items,
    ];
  }

  /**
   * Builds metric summary items.
   *
   * @param array<string, mixed> $metrics
   *
   * @return array<string, mixed>
   */
  private function buildMetricCards(array $metrics): array {
    if (empty($metrics)) {
      return [
        '#markup' => '<p>' . $this->t('No metrics collected yet. Metrics are sampled on frontend page loads.') . '</p>',
      ];
    }

    return [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('Samples: <strong>@v</strong>', ['@v' => $metrics['sample_count']]),
        $this->t('Avg Page Load: <strong>@v ms</strong>', ['@v' => $metrics['avg_page_load_time']]),
        $this->t('Avg Twig Render: <strong>@v ms</strong>', ['@v' => $metrics['avg_twig_render_time']]),
        $this->t('Avg DB Queries/page: <strong>@v</strong>', ['@v' => $metrics['avg_db_query_count']]),
        $this->t('Avg Memory: <strong>@v</strong>', ['@v' => $this->formatBytes($metrics['avg_memory_usage'])]),
        $this->t('Render Cache Hit Ratio: <strong>@v%</strong>', ['@v' => round(($metrics['cache_hit_ratio'] ?? 0) * 100, 1)]),
        $this->t('Slow Requests (24h): <strong>@v</strong>', ['@v' => $metrics['slow_requests']]),
        $this->t('Avg Response Size: <strong>@v</strong>', ['@v' => $this->formatBytes($metrics['avg_response_size'])]),
      ],
    ];
  }

  /**
   * Builds cache module status list.
   *
   * @param array<string, mixed> $report
   *
   * @return array<string, mixed>
   */
  private function buildCacheModuleStatus(array $report): array {
    $items = [];

    foreach ($report['modules'] as $module => $enabled) {
      $icon = $enabled ? '✅' : '❌';
      $items[] = "$icon $module";
    }

    foreach ($report['settings'] as $key => $value) {
      $display = is_bool($value) ? ($value ? 'Yes' : 'No') : $value;
      $items[] = $this->t('@key: @val', ['@key' => str_replace('_', ' ', $key), '@val' => $display]);
    }

    foreach ($report['issues'] as $issue) {
      $icon = $issue['severity'] === 'error' ? '🔴' : '🟡';
      $items[] = "$icon {$issue['issue']}";
    }

    return ['#theme' => 'item_list', '#items' => $items];
  }

  /**
   * Builds recommendations table.
   *
   * @param array<int, array<string, string>> $recs
   *
   * @return array<string, mixed>
   */
  private function buildRecommendationsTable(array $recs): array {
    $rows = [];
    foreach ($recs as $rec) {
      $priorityClass = match ($rec['priority'] ?? 'low') {
        'high' => 'color-error',
        'medium' => 'color-warning',
        default => '',
      };
      $rows[] = [
        ['data' => strtoupper($rec['priority'] ?? 'LOW'), 'class' => $priorityClass ? [$priorityClass] : []],
        $rec['category'] ?? '',
        $rec['title'] ?? '',
        $rec['action'] ?? '',
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [$this->t('Priority'), $this->t('Category'), $this->t('Issue'), $this->t('Action')],
      '#rows' => $rows,
      '#empty' => $this->t('No recommendations — great job!'),
    ];
  }

  /**
   * Builds log summary table.
   *
   * @param array<string, int> $summary
   *
   * @return array<string, mixed>
   */
  private function buildLogSummaryTable(array $summary): array {
    $rows = [];
    foreach ($summary as $type => $count) {
      $rows[] = [$type, $count];
    }

    return [
      '#type' => 'table',
      '#header' => [$this->t('Log Type'), $this->t('Count (all time)')],
      '#rows' => $rows,
    ];
  }

  /**
   * Formats bytes into a human-readable string.
   */
  private function formatBytes(int $bytes): string {
    if ($bytes >= 1073741824) {
      return round($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
      return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
      return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
  }

  /**
   * Formats milliseconds for display.
   */
  private function formatMs(float $ms): string {
    return number_format($ms, 2) . ' ms';
  }

}
