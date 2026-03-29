<?php

declare(strict_types=1);

namespace Drupal\Tests\d11_performance_optimizer\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\d11_performance_optimizer\Service\PerformanceAnalyzerService;

/**
 * Unit tests for PerformanceAnalyzerService.
 *
 * @group d11_performance_optimizer
 * @coversDefaultClass \Drupal\d11_performance_optimizer\Service\PerformanceAnalyzerService
 */
final class PerformanceAnalyzerServiceTest extends UnitTestCase {

  private PerformanceAnalyzerService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['sampling_rate', 1],
      ['slow_request_threshold', 2000],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $loggerChannel = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($loggerChannel);

    $database = $this->createMock(Connection::class);
    $state = $this->createMock(StateInterface::class);

    $this->service = new PerformanceAnalyzerService(
      $database,
      $loggerFactory,
      $configFactory,
      $state,
    );
  }

  /**
   * @covers ::startTimer
   * @covers ::stopTimer
   */
  public function testTimerStartStop(): void {
    $this->service->startTimer('test');
    usleep(10000); // 10ms
    $elapsed = $this->service->stopTimer('test');

    $this->assertGreaterThan(5.0, $elapsed, 'Elapsed time should be > 5ms.');
    $this->assertLessThan(500.0, $elapsed, 'Elapsed time should be < 500ms.');
  }

  /**
   * @covers ::stopTimer
   */
  public function testStopTimerWithoutStartReturnsZero(): void {
    $result = $this->service->stopTimer('nonexistent_timer');
    $this->assertSame(0.0, $result);
  }

  /**
   * @covers ::recordCacheHit
   * @covers ::recordCacheMiss
   */
  public function testCacheCounters(): void {
    // These should not throw; counters are internal.
    $this->service->recordCacheHit();
    $this->service->recordCacheHit();
    $this->service->recordCacheMiss();
    // No assertion needed beyond no exception.
    $this->assertTrue(TRUE);
  }

  /**
   * @covers ::setDbQueryCount
   * @covers ::setTwigRenderTime
   * @covers ::setAssetPayloadSize
   */
  public function testSetterMethodsDontThrow(): void {
    $this->service->setDbQueryCount(42);
    $this->service->setTwigRenderTime(123.45);
    $this->service->setAssetPayloadSize(102400);
    $this->assertTrue(TRUE);
  }

}
