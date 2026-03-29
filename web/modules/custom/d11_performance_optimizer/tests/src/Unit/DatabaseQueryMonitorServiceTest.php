<?php

declare(strict_types=1);

namespace Drupal\Tests\d11_performance_optimizer\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\d11_performance_optimizer\Service\DatabaseQueryMonitorService;

/**
 * Unit tests for DatabaseQueryMonitorService.
 *
 * @group d11_performance_optimizer
 * @coversDefaultClass \Drupal\d11_performance_optimizer\Service\DatabaseQueryMonitorService
 */
final class DatabaseQueryMonitorServiceTest extends UnitTestCase {

  private DatabaseQueryMonitorService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['enable_query_monitoring', TRUE],
      ['slow_query_threshold', 100.0],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $loggerChannel = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($loggerChannel);

    $database = $this->createMock(Connection::class);

    $this->service = new DatabaseQueryMonitorService(
      $database,
      $loggerFactory,
      $configFactory,
    );
  }

  /**
   * @covers ::getRequestSummary
   */
  public function testGetRequestSummaryEmpty(): void {
    $summary = $this->service->getRequestSummary();

    $this->assertSame(0, $summary['total_queries']);
    $this->assertSame(0.0, $summary['total_time']);
    $this->assertSame(0, $summary['slow_query_count']);
    $this->assertIsArray($summary['n_plus_one_patterns']);
  }

  /**
   * @covers ::resetBuffer
   */
  public function testResetBufferClearsState(): void {
    $this->service->resetBuffer();
    $summary = $this->service->getRequestSummary();
    $this->assertSame(0, $summary['total_queries']);
  }

  /**
   * @covers ::detectNPlusOnePatterns
   */
  public function testNPlusOneDetectionBelowThreshold(): void {
    // Record 4 identical-looking queries (below the 5-occurrence threshold).
    for ($i = 0; $i < 4; $i++) {
      // We can't easily call recordQuery without DB, but detectNPlusOnePatterns
      // operates on the internal buffer. Access via reflection.
      $reflection = new \ReflectionClass($this->service);
      $bufferProp = $reflection->getProperty('queryBuffer');
      $bufferProp->setAccessible(TRUE);
      $buffer = $bufferProp->getValue($this->service);
      $buffer[] = ['query' => "SELECT * FROM {node} WHERE nid = $i", 'execution_time' => 10.0, 'is_slow' => FALSE];
      $bufferProp->setValue($this->service, $buffer);
    }

    $patterns = $this->service->detectNPlusOnePatterns();
    $this->assertEmpty($patterns, 'Should not detect N+1 with only 4 occurrences.');
  }

  /**
   * @covers ::detectNPlusOnePatterns
   */
  public function testNPlusOneDetectionAboveThreshold(): void {
    $reflection = new \ReflectionClass($this->service);
    $bufferProp = $reflection->getProperty('queryBuffer');
    $bufferProp->setAccessible(TRUE);
    $buffer = [];

    for ($i = 0; $i < 6; $i++) {
      $buffer[] = ['query' => "SELECT * FROM {node} WHERE nid = $i", 'execution_time' => 10.0, 'is_slow' => FALSE];
    }
    $bufferProp->setValue($this->service, $buffer);

    $patterns = $this->service->detectNPlusOnePatterns();
    $this->assertNotEmpty($patterns, 'Should detect N+1 with 6 occurrences of same pattern.');
    $this->assertGreaterThanOrEqual(6, $patterns[0]['count']);
  }

}
