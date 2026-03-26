<?php

declare(strict_types=1);

namespace Drupal\d11_performance_optimizer\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Monitors database queries for performance issues.
 */
final class DatabaseQueryMonitorService {

  /**
   * In-memory buffer of queries captured this request.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $queryBuffer = [];

  public function __construct(
    private readonly Connection $database,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Records a single query execution.
   *
   * @param string $query
   *   The SQL query string.
   * @param float $executionTime
   *   Execution time in milliseconds.
   * @param string $path
   *   The current request path.
   */
  public function recordQuery(string $query, float $executionTime, string $path = ''): void {
    $config = $this->configFactory->get('d11_performance_optimizer.settings');
    if (!$config->get('enable_query_monitoring')) {
      return;
    }

    $threshold = (float) $config->get('slow_query_threshold');
    $isSlowQuery = $executionTime >= $threshold;

    $this->queryBuffer[] = [
      'query' => $query,
      'execution_time' => $executionTime,
      'is_slow' => $isSlowQuery,
    ];

    if ($isSlowQuery) {
      $this->persistSlowQuery($query, $executionTime, $path);
    }
  }

  /**
   * Persists a slow query to the database.
   */
  private function persistSlowQuery(string $query, float $executionTime, string $path): void {
    $normalized = $this->normalizeQuery($query);
    $hash = md5($normalized);

    try {
      // Upsert: increment occurrence count if the same query pattern was seen.
      $existing = $this->database->select('d11_slow_queries', 'q')
        ->fields('q', ['id', 'occurrence_count'])
        ->condition('query_hash', $hash)
        ->execute()
        ->fetchObject();

      if ($existing) {
        $this->database->update('d11_slow_queries')
          ->fields([
            'occurrence_count' => $existing->occurrence_count + 1,
            'execution_time' => $executionTime,
            'timestamp' => time(),
          ])
          ->condition('id', $existing->id)
          ->execute();
      }
      else {
        $callStack = $this->captureCallStack();
        $this->database->insert('d11_slow_queries')
          ->fields([
            'query_hash' => $hash,
            'query' => substr($query, 0, 65535),
            'execution_time' => $executionTime,
            'call_stack' => $callStack,
            'occurrence_count' => 1,
            'path' => substr($path, 0, 2048),
            'timestamp' => time(),
          ])
          ->execute();
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('d11_performance_optimizer')
        ->error('Failed to persist slow query: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  /**
   * Normalizes a query for deduplication by removing literal values.
   */
  private function normalizeQuery(string $query): string {
    // Replace quoted strings with placeholder.
    $query = preg_replace("/'[^']*'/", "'?'", $query) ?? $query;
    // Replace numeric literals.
    $query = preg_replace('/\b\d+\b/', '?', $query) ?? $query;
    return trim(preg_replace('/\s+/', ' ', $query) ?? $query);
  }

  /**
   * Captures a simplified call stack for diagnostics.
   */
  private function captureCallStack(): string {
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
    $lines = [];
    foreach ($trace as $frame) {
      if (isset($frame['file'], $frame['line'])) {
        $lines[] = $frame['file'] . ':' . $frame['line'];
      }
    }
    return implode("\n", array_slice($lines, 2, 8));
  }

  /**
   * Detects potential N+1 query patterns in the current buffer.
   *
   * @return array<int, array<string, mixed>>
   *   List of detected N+1 patterns.
   */
  public function detectNPlusOnePatterns(): array {
    $patterns = [];
    $normalized = [];

    foreach ($this->queryBuffer as $entry) {
      $norm = $this->normalizeQuery($entry['query']);
      $normalized[$norm] = ($normalized[$norm] ?? 0) + 1;
    }

    foreach ($normalized as $query => $count) {
      if ($count >= 5) {
        $patterns[] = [
          'query' => $query,
          'count' => $count,
          'recommendation' => 'Potential N+1 pattern detected. Consider using EntityQuery with pre-loading or views.',
        ];
      }
    }

    return $patterns;
  }

  /**
   * Returns summary statistics for the current request.
   *
   * @return array<string, mixed>
   */
  public function getRequestSummary(): array {
    $totalTime = array_sum(array_column($this->queryBuffer, 'execution_time'));
    $slowQueries = array_filter($this->queryBuffer, fn($q) => $q['is_slow']);
    $nPlusOne = $this->detectNPlusOnePatterns();

    return [
      'total_queries' => count($this->queryBuffer),
      'total_time' => $totalTime,
      'slow_query_count' => count($slowQueries),
      'n_plus_one_patterns' => $nPlusOne,
      'duplicate_count' => count($this->queryBuffer) - count(array_unique(array_column($this->queryBuffer, 'query'))),
    ];
  }

  /**
   * Returns recent slow queries from the database.
   *
   * @param int $limit
   *   Number of results to return.
   *
   * @return array<int, object>
   */
  public function getRecentSlowQueries(int $limit = 25): array {
    try {
      return $this->database->select('d11_slow_queries', 'q')
        ->fields('q')
        ->orderBy('execution_time', 'DESC')
        ->range(0, $limit)
        ->execute()
        ->fetchAll();
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Clears the in-request query buffer.
   */
  public function resetBuffer(): void {
    $this->queryBuffer = [];
  }

}
