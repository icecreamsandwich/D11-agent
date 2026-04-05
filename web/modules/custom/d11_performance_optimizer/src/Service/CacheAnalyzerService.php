<?php

declare(strict_types=1);

namespace Drupal\d11_performance_optimizer\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Analyzes Drupal cache configuration and performance.
 */
final class CacheAnalyzerService {

  public function __construct(
    private readonly CacheBackendInterface $cacheBackend,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Returns a full cache health report.
   *
   * @return array<string, mixed>
   */
  public function analyze(): array {
    $issues = [];
    $recommendations = [];

    // Check page_cache module.
    if (!$this->moduleHandler->moduleExists('page_cache')) {
      $issues[] = [
        'severity' => 'error',
        'issue' => 'Page Cache module is not enabled.',
        'recommendation' => 'Enable the Page Cache module to cache full page responses for anonymous users.',
      ];
    }

    // Check dynamic_page_cache module.
    if (!$this->moduleHandler->moduleExists('dynamic_page_cache')) {
      $issues[] = [
        'severity' => 'error',
        'issue' => 'Dynamic Page Cache module is not enabled.',
        'recommendation' => 'Enable Dynamic Page Cache to cache page variations per user context.',
      ];
    }

    // Check render cache configuration.
    $renderConfig = $this->configFactory->get('system.performance');
    $cacheMaxAge = $renderConfig->get('cache.page.max_age');

    if ($cacheMaxAge !== NULL && (int) $cacheMaxAge < 3600) {
      $issues[] = [
        'severity' => 'warning',
        'issue' => sprintf('Page cache max-age is set very low: %ds.', $cacheMaxAge),
        'recommendation' => 'Increase page cache max-age to at least 3600 seconds (1 hour) for better cache coverage.',
      ];
    }

    // Check CSS/JS aggregation.
    $performanceConfig = $this->configFactory->get('system.performance');
    if (!$performanceConfig->get('css.preprocess')) {
      $issues[] = [
        'severity' => 'warning',
        'issue' => 'CSS aggregation is disabled.',
        'recommendation' => 'Enable CSS aggregation in system performance settings to reduce HTTP requests.',
      ];
      $recommendations[] = 'Enable CSS aggregation at /admin/config/development/performance.';
    }

    if (!$performanceConfig->get('js.preprocess')) {
      $issues[] = [
        'severity' => 'warning',
        'issue' => 'JavaScript aggregation is disabled.',
        'recommendation' => 'Enable JS aggregation to reduce HTTP requests and improve performance.',
      ];
      $recommendations[] = 'Enable JavaScript aggregation at /admin/config/development/performance.';
    }

    return [
      'issues' => $issues,
      'recommendations' => $recommendations,
      'modules' => [
        'page_cache' => $this->moduleHandler->moduleExists('page_cache'),
        'dynamic_page_cache' => $this->moduleHandler->moduleExists('dynamic_page_cache'),
        'big_pipe' => $this->moduleHandler->moduleExists('big_pipe'),
        'internal_page_cache' => $this->moduleHandler->moduleExists('page_cache'),
      ],
      'settings' => [
        'css_aggregation' => (bool) $performanceConfig->get('css.preprocess'),
        'js_aggregation' => (bool) $performanceConfig->get('js.preprocess'),
        'page_cache_max_age' => $cacheMaxAge,
      ],
    ];
  }

  /**
   * Analyzes a render array for missing cache metadata.
   *
   * @param array<string, mixed> $renderArray
   *   The render array to inspect.
   *
   * @return array<int, string>
   *   List of issues found.
   */
  public function analyzeRenderArray(array $renderArray): array {
    $issues = [];

    if (!isset($renderArray['#cache'])) {
      $issues[] = 'Render array is missing #cache metadata entirely.';
      return $issues;
    }

    $cache = $renderArray['#cache'];

    if (empty($cache['contexts']) && empty($cache['tags']) && !isset($cache['max-age'])) {
      $issues[] = 'Render array has empty #cache metadata — will not benefit from render caching.';
    }

    if (!isset($cache['max-age'])) {
      $issues[] = 'Missing cache max-age — defaults to Cache::PERMANENT which may be incorrect.';
    }

    return $issues;
  }

}
