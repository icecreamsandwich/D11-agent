<?php

declare(strict_types=1);

namespace Drupal\Tests\d11_performance_optimizer\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel test verifying database schema is installed correctly.
 *
 * @group d11_performance_optimizer
 */
final class PerformanceMetricsSchemaTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'd11_performance_optimizer',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('d11_performance_optimizer', [
      'd11_performance_metrics',
      'd11_performance_logs',
      'd11_slow_queries',
    ]);
    $this->installConfig(['d11_performance_optimizer']);
  }

  /**
   * Tests the d11_performance_metrics table exists and is writable.
   */
  public function testMetricsTableExists(): void {
    $database = $this->container->get('database');

    $database->insert('d11_performance_metrics')
      ->fields([
        'path' => '/test-path',
        'page_load_time' => 150.5,
        'twig_render_time' => 30.0,
        'db_query_count' => 12,
        'memory_usage' => 1048576,
        'render_cache_hits' => 5,
        'render_cache_misses' => 2,
        'asset_payload_size' => 204800,
        'time_to_first_byte' => 80.0,
        'response_size' => 51200,
        'timestamp' => time(),
      ])
      ->execute();

    $count = $database->select('d11_performance_metrics', 'm')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertEquals(1, (int) $count, 'One metric row should have been inserted.');
  }

  /**
   * Tests the d11_performance_logs table exists and is writable.
   */
  public function testLogsTableExists(): void {
    $database = $this->container->get('database');

    $database->insert('d11_performance_logs')
      ->fields([
        'log_type' => 'slow_request',
        'severity' => 'warning',
        'path' => '/test',
        'message' => 'Test log entry.',
        'context' => NULL,
        'timestamp' => time(),
      ])
      ->execute();

    $count = $database->select('d11_performance_logs', 'l')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertEquals(1, (int) $count);
  }

  /**
   * Tests the d11_slow_queries table exists and is writable.
   */
  public function testSlowQueriesTableExists(): void {
    $database = $this->container->get('database');

    $database->insert('d11_slow_queries')
      ->fields([
        'query_hash' => md5('SELECT 1'),
        'query' => 'SELECT 1',
        'execution_time' => 250.0,
        'call_stack' => '',
        'occurrence_count' => 1,
        'path' => '/',
        'timestamp' => time(),
      ])
      ->execute();

    $this->assertEquals(
      1,
      (int) $database->select('d11_slow_queries', 'q')->countQuery()->execute()->fetchField()
    );
  }

  /**
   * Tests default configuration is loaded.
   */
  public function testDefaultConfigLoaded(): void {
    $config = $this->container->get('config.factory')
      ->get('d11_performance_optimizer.settings');

    $this->assertEquals(100, $config->get('slow_query_threshold'));
    $this->assertEquals(2000, $config->get('slow_request_threshold'));
    $this->assertTrue($config->get('enable_seo_optimization'));
    $this->assertSame(10, $config->get('sampling_rate'));
  }

}
