<?php

declare(strict_types=1);

namespace Drupal\d11_performance_optimizer\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Analyzes and records page performance metrics.
 */
final class PerformanceAnalyzerService {

  /**
   * Stores per-request timing markers.
   *
   * @var array<string, float>
   */
  private array $timers = [];

  /**
   * Accumulated metrics for the current request.
   *
   * @var array<string, mixed>
   */
  private array $metrics = [];

  public function __construct(
    private readonly Connection $database,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
  ) {}

  /**
   * Starts a named timer.
   */
  public function startTimer(string $name): void {
    $this->timers[$name] = microtime(TRUE);
  }

  /**
   * Stops a named timer and returns elapsed milliseconds.
   */
  public function stopTimer(string $name): float {
    if (!isset($this->timers[$name])) {
      return 0.0;
    }
    $elapsed = (microtime(TRUE) - $this->timers[$name]) * 1000;
    unset($this->timers[$name]);
    return $elapsed;
  }

  /**
   * Records the start of a request.
   */
  public function beginRequest(string $path): void {
    $this->startTimer('page_load');
    $this->metrics = [
      'path' => $path,
      'memory_start' => memory_get_usage(TRUE),
      'render_cache_hits' => 0,
      'render_cache_misses' => 0,
    ];
  }

  /**
   * Records the end of a request and persists metrics.
   *
   * @param int $responseSize
   *   The response body size in bytes.
   */
  public function endRequest(int $responseSize = 0): void {
    $config = $this->configFactory->get('d11_performance_optimizer.settings');

    // Only record on configured sampling rate.
    $samplingRate = max(1, (int) $config->get('sampling_rate'));
    if ($samplingRate > 1 && (rand(1, $samplingRate) !== 1)) {
      return;
    }

    $pageLoadTime = $this->stopTimer('page_load');
    $memoryUsage = memory_get_peak_usage(TRUE);

    $record = [
      'path' => substr($this->metrics['path'] ?? '', 0, 2048),
      'page_load_time' => $pageLoadTime,
      'twig_render_time' => $this->metrics['twig_render_time'] ?? 0.0,
      'db_query_count' => $this->metrics['db_query_count'] ?? 0,
      'memory_usage' => $memoryUsage,
      'render_cache_hits' => $this->metrics['render_cache_hits'] ?? 0,
      'render_cache_misses' => $this->metrics['render_cache_misses'] ?? 0,
      'asset_payload_size' => $this->metrics['asset_payload_size'] ?? 0,
      'time_to_first_byte' => $this->metrics['ttfb'] ?? 0.0,
      'response_size' => $responseSize,
      'timestamp' => time(),
    ];

    try {
      $this->database->insert('d11_performance_metrics')
        ->fields($record)
        ->execute();
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('d11_performance_optimizer')
        ->error('Failed to store performance metric: @msg', ['@msg' => $e->getMessage()]);
    }

    $this->state->set('d11_performance_optimizer.last_analysis', time());
  }

  /**
   * Increments the render cache hit counter.
   */
  public function recordCacheHit(): void {
    $this->metrics['render_cache_hits'] = ($this->metrics['render_cache_hits'] ?? 0) + 1;
  }

  /**
   * Increments the render cache miss counter.
   */
  public function recordCacheMiss(): void {
    $this->metrics['render_cache_misses'] = ($this->metrics['render_cache_misses'] ?? 0) + 1;
  }

  /**
   * Sets the Twig render time.
   */
  public function setTwigRenderTime(float $ms): void {
    $this->metrics['twig_render_time'] = $ms;
  }

  /**
   * Sets the database query count.
   */
  public function setDbQueryCount(int $count): void {
    $this->metrics['db_query_count'] = $count;
  }

  /**
   * Sets total asset payload size.
   */
  public function setAssetPayloadSize(int $bytes): void {
    $this->metrics['asset_payload_size'] = $bytes;
  }

  /**
   * Returns aggregated metrics for the dashboard.
   *
   * @param int $hours
   *   How many hours back to aggregate.
   *
   * @return array<string, mixed>
   */
  public function getAggregatedMetrics(int $hours = 24): array {
    $since = time() - ($hours * 3600);

    try {
      $result = $this->database->select('d11_performance_metrics', 'm')
        ->fields('m')
        ->condition('timestamp', $since, '>')
        ->orderBy('timestamp', 'DESC')
        ->range(0, 1000)
        ->execute()
        ->fetchAll();

      if (empty($result)) {
        return [];
      }

      $count = count($result);
      $aggregate = [
        'sample_count' => $count,
        'avg_page_load_time' => 0.0,
        'avg_twig_render_time' => 0.0,
        'avg_db_query_count' => 0.0,
        'avg_memory_usage' => 0,
        'total_cache_hits' => 0,
        'total_cache_misses' => 0,
        'avg_response_size' => 0,
        'slow_requests' => 0,
      ];

      $slowThreshold = (float) \Drupal::config('d11_performance_optimizer.settings')->get('slow_request_threshold');

      foreach ($result as $row) {
        $aggregate['avg_page_load_time'] += $row->page_load_time;
        $aggregate['avg_twig_render_time'] += $row->twig_render_time;
        $aggregate['avg_db_query_count'] += $row->db_query_count;
        $aggregate['avg_memory_usage'] += $row->memory_usage;
        $aggregate['total_cache_hits'] += $row->render_cache_hits;
        $aggregate['total_cache_misses'] += $row->render_cache_misses;
        $aggregate['avg_response_size'] += $row->response_size;
        if ($row->page_load_time > $slowThreshold) {
          $aggregate['slow_requests']++;
        }
      }

      $aggregate['avg_page_load_time'] = round($aggregate['avg_page_load_time'] / $count, 2);
      $aggregate['avg_twig_render_time'] = round($aggregate['avg_twig_render_time'] / $count, 2);
      $aggregate['avg_db_query_count'] = round($aggregate['avg_db_query_count'] / $count, 1);
      $aggregate['avg_memory_usage'] = (int) ($aggregate['avg_memory_usage'] / $count);
      $aggregate['avg_response_size'] = (int) ($aggregate['avg_response_size'] / $count);

      $totalCache = $aggregate['total_cache_hits'] + $aggregate['total_cache_misses'];
      $aggregate['cache_hit_ratio'] = $totalCache > 0
        ? round($aggregate['total_cache_hits'] / $totalCache, 3)
        : 0.0;

      return $aggregate;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('d11_performance_optimizer')
        ->error('Failed to aggregate metrics: @msg', ['@msg' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Returns recent metric rows for display in the dashboard table.
   *
   * @param int $limit
   *   Number of rows to return.
   *
   * @return array<int, object>
   */
  public function getRecentMetrics(int $limit = 50): array {
    try {
      return $this->database->select('d11_performance_metrics', 'm')
        ->fields('m')
        ->orderBy('timestamp', 'DESC')
        ->range(0, $limit)
        ->execute()
        ->fetchAll();
    }
    catch (\Exception $e) {
      return [];
    }
  }

}
