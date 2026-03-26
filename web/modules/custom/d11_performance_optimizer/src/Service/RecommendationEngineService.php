<?php

declare(strict_types=1);

namespace Drupal\d11_performance_optimizer\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Generates actionable performance recommendations.
 */
final class RecommendationEngineService {

  public function __construct(
    private readonly PerformanceAnalyzerService $performanceAnalyzer,
    private readonly CacheAnalyzerService $cacheAnalyzer,
    private readonly DatabaseQueryMonitorService $queryMonitor,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Generates a prioritized list of performance recommendations.
   *
   * @return array<int, array<string, string>>
   *   Recommendations sorted by priority (high, medium, low).
   */
  public function generate(): array {
    $recommendations = [];
    $config = $this->configFactory->get('d11_performance_optimizer.settings');
    $maxRecommendations = (int) $config->get('max_recommendations');

    // Analyze metrics.
    $metrics = $this->performanceAnalyzer->getAggregatedMetrics(24);
    $cacheReport = $this->cacheAnalyzer->analyze();

    // === Cache recommendations ===
    foreach ($cacheReport['issues'] as $issue) {
      $recommendations[] = [
        'priority' => $issue['severity'] === 'error' ? 'high' : 'medium',
        'category' => 'Cache',
        'title' => $issue['issue'],
        'description' => $issue['recommendation'],
        'action' => 'Review cache configuration at /admin/config/development/performance.',
      ];
    }

    // === Performance metric recommendations ===
    if (!empty($metrics)) {
      $slowThreshold = (float) $config->get('slow_request_threshold');
      if ($metrics['avg_page_load_time'] > $slowThreshold) {
        $recommendations[] = [
          'priority' => 'high',
          'category' => 'Performance',
          'title' => sprintf('Average page load time is %.1fms (threshold: %.0fms).', $metrics['avg_page_load_time'], $slowThreshold),
          'description' => 'Pages are loading slower than the configured threshold.',
          'action' => 'Profile slow pages with the Database Metrics report. Consider enabling BigPipe and render caching.',
        ];
      }

      if ($metrics['avg_db_query_count'] > 50) {
        $recommendations[] = [
          'priority' => 'high',
          'category' => 'Database',
          'title' => sprintf('Average of %.1f database queries per page.', $metrics['avg_db_query_count']),
          'description' => 'Excessive database queries increase page load time significantly.',
          'action' => 'Use EntityQuery with pre-loaded fields. Use Views with caching. Check for N+1 query patterns.',
        ];
      }

      if ($metrics['avg_memory_usage'] > (int) $config->get('high_memory_threshold')) {
        $recommendations[] = [
          'priority' => 'medium',
          'category' => 'Performance',
          'title' => sprintf('High average memory usage: %sMB.', round($metrics['avg_memory_usage'] / 1048576, 1)),
          'description' => 'High memory usage can cause server instability under load.',
          'action' => 'Profile memory-intensive operations. Reduce entity loading scope. Use lazy builders for non-critical content.',
        ];
      }

      if (isset($metrics['cache_hit_ratio']) && $metrics['cache_hit_ratio'] < (float) $config->get('cache_hit_ratio_threshold')) {
        $recommendations[] = [
          'priority' => 'medium',
          'category' => 'Cache',
          'title' => sprintf('Low render cache hit ratio: %.0f%%.', $metrics['cache_hit_ratio'] * 100),
          'description' => 'Low cache hit rate means most pages are being rendered from scratch.',
          'action' => 'Ensure render arrays include #cache metadata with appropriate contexts and tags. Use the Drupal Cache API.',
        ];
      }
    }

    // === Slow query recommendations ===
    $slowQueries = $this->queryMonitor->getRecentSlowQueries(5);
    if (!empty($slowQueries)) {
      $recommendations[] = [
        'priority' => 'high',
        'category' => 'Database',
        'title' => sprintf('%d slow queries detected.', count($slowQueries)),
        'description' => 'Slow database queries are a top cause of poor page performance.',
        'action' => 'Review slow queries in the Database Metrics tab. Add database indexes, use entity caching, or refactor EntityQuery calls.',
      ];
    }

    // === Asset optimization recommendations ===
    $systemPerf = $this->configFactory->get('system.performance');
    if (!$systemPerf->get('css.preprocess')) {
      $recommendations[] = [
        'priority' => 'medium',
        'category' => 'Assets',
        'title' => 'CSS aggregation is disabled.',
        'description' => 'Disabling CSS aggregation increases page load time by requiring multiple HTTP requests.',
        'action' => 'Enable CSS aggregation at /admin/config/development/performance.',
      ];
    }

    if (!$systemPerf->get('js.preprocess')) {
      $recommendations[] = [
        'priority' => 'medium',
        'category' => 'Assets',
        'title' => 'JavaScript aggregation is disabled.',
        'description' => 'Disabling JS aggregation increases page load time.',
        'action' => 'Enable JS aggregation at /admin/config/development/performance.',
      ];
    }

    // === Generic best-practice recommendations ===
    $recommendations[] = [
      'priority' => 'low',
      'category' => 'Rendering',
      'title' => 'Use lazy builders for personalized content.',
      'description' => 'Content that varies per user should use #lazy_builder to avoid cache bubbling.',
      'action' => 'Refactor user-specific render elements to use lazy builders. See BigPipe module docs.',
    ];

    $recommendations[] = [
      'priority' => 'low',
      'category' => 'Database',
      'title' => 'Enable Drupal Entity Cache.',
      'description' => 'The EntityCache module (if applicable) stores entities in the cache layer to reduce database hits.',
      'action' => 'Review entity loading patterns. Use $entity->getCacheContexts() and proper cache tags.',
    ];

    // Sort by priority: high first.
    usort($recommendations, function (array $a, array $b): int {
      $order = ['high' => 0, 'medium' => 1, 'low' => 2];
      return ($order[$a['priority']] ?? 3) <=> ($order[$b['priority']] ?? 3);
    });

    return array_slice($recommendations, 0, $maxRecommendations);
  }

}
