<?php

declare(strict_types=1);

namespace Drupal\d11_performance_optimizer\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Logs performance events to the d11_performance_logs table.
 */
final class PerformanceLoggerService {

  public const TYPE_SLOW_REQUEST = 'slow_request';
  public const TYPE_SLOW_QUERY = 'slow_query';
  public const TYPE_HIGH_MEMORY = 'high_memory';
  public const TYPE_LARGE_RESPONSE = 'large_response';
  public const TYPE_CACHE_MISS = 'cache_miss';
  public const TYPE_RENDER_ISSUE = 'render_issue';

  public function __construct(
    private readonly Connection $database,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Logs a performance event.
   *
   * @param string $type
   *   One of the TYPE_* constants.
   * @param string $message
   *   Human-readable description of the event.
   * @param string $path
   *   The request path.
   * @param string $severity
   *   One of 'info', 'warning', 'error'.
   * @param array<string, mixed> $context
   *   Additional context data (will be serialized).
   */
  public function log(
    string $type,
    string $message,
    string $path = '',
    string $severity = 'warning',
    array $context = [],
  ): void {
    $config = $this->configFactory->get('d11_performance_optimizer.settings');
    if (!$config->get('enable_performance_logging')) {
      return;
    }

    try {
      $this->database->insert('d11_performance_logs')
        ->fields([
          'log_type' => substr($type, 0, 64),
          'severity' => $severity,
          'path' => substr($path, 0, 2048),
          'message' => $message,
          'context' => !empty($context) ? serialize($context) : NULL,
          'timestamp' => time(),
        ])
        ->execute();
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('d11_performance_optimizer')
        ->error('Failed to write performance log: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  /**
   * Logs a slow HTTP request.
   *
   * @param string $path
   *   The request path.
   * @param float $loadTime
   *   Page load time in milliseconds.
   */
  public function logSlowRequest(string $path, float $loadTime): void {
    $this->log(
      self::TYPE_SLOW_REQUEST,
      sprintf('Slow request: %.2fms for %s', $loadTime, $path),
      $path,
      'warning',
      ['load_time_ms' => $loadTime],
    );
  }

  /**
   * Logs a high memory usage event.
   *
   * @param string $path
   *   The request path.
   * @param int $memoryBytes
   *   Memory usage in bytes.
   */
  public function logHighMemory(string $path, int $memoryBytes): void {
    $this->log(
      self::TYPE_HIGH_MEMORY,
      sprintf('High memory usage: %sMB on %s', round($memoryBytes / 1048576, 1), $path),
      $path,
      'warning',
      ['memory_bytes' => $memoryBytes],
    );
  }

  /**
   * Logs a large HTTP response.
   *
   * @param string $path
   *   The request path.
   * @param int $sizeBytes
   *   Response size in bytes.
   */
  public function logLargeResponse(string $path, int $sizeBytes): void {
    $this->log(
      self::TYPE_LARGE_RESPONSE,
      sprintf('Large response: %sKB for %s', round($sizeBytes / 1024, 1), $path),
      $path,
      'warning',
      ['size_bytes' => $sizeBytes],
    );
  }

  /**
   * Returns recent log entries for the dashboard.
   *
   * @param int $limit
   *   Maximum number of entries to return.
   * @param string|null $type
   *   Optional log type filter.
   *
   * @return array<int, object>
   */
  public function getRecentLogs(int $limit = 50, ?string $type = NULL): array {
    try {
      $query = $this->database->select('d11_performance_logs', 'l')
        ->fields('l')
        ->orderBy('timestamp', 'DESC')
        ->range(0, $limit);

      if ($type !== NULL) {
        $query->condition('log_type', $type);
      }

      return $query->execute()->fetchAll();
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Returns log counts grouped by type.
   *
   * @return array<string, int>
   */
  public function getLogSummary(): array {
    try {
      $query = $this->database->select('d11_performance_logs', 'l')
        ->fields('l', ['log_type'])
        ->groupBy('l.log_type');
      $query->addExpression('COUNT(*)', 'cnt');
      $result = $query->execute()->fetchAll();

      $summary = [];
      foreach ($result as $row) {
        $summary[$row->log_type] = (int) $row->cnt;
      }
      return $summary;
    }
    catch (\Exception $e) {
      return [];
    }
  }

}
