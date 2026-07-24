<?php

declare(strict_types=1);

namespace Drupal\Tests\spacex_launches\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use Drupal\spacex_launches\Service\LaunchesApiService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for LaunchesApiService.
 *
 * @group spacex_launches
 * @coversDefaultClass \Drupal\spacex_launches\Service\LaunchesApiService
 */
final class LaunchesApiServiceTest extends UnitTestCase {

  /**
   * @covers ::getLaunches
   */
  public function testGetLaunchesReturnsNormalizedResults(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $cacheBackend = $this->createMock(CacheBackendInterface::class);
    $cacheBackend->method('get')->willReturn(FALSE);
    $cacheBackend->expects($this->once())
      ->method('set');

    $cacheInvalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $cacheInvalidator->expects($this->once())
      ->method('invalidateTags')
      ->with(['spacex_launches:rendered']);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['cache_ttl_minutes', 15],
      ['results_limit', 5],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('spacex_launches.settings')->willReturn($config);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1700000000);

    $logger = $this->createMock(LoggerInterface::class);

    $payload = [
      'count' => 2,
      'results' => [
        [
          'name' => 'Mission One',
          'net' => '2026-07-15T00:00:00Z',
          'rocket' => ['configuration' => ['full_name' => 'Falcon 9 Block 5']],
          'mission' => ['name' => 'Starlink', 'type' => 'Communications'],
          'launch_service_provider' => ['name' => 'SpaceX'],
          'status' => ['name' => 'Go'],
          'pad' => [
            'name' => 'SLC-40',
            'location' => ['name' => 'Cape Canaveral'],
            'country' => ['name' => 'United States'],
          ],
          'image' => ['image_url' => 'https://example.com/a.jpg', 'name' => 'Rocket A'],
        ],
        [
          'name' => 'Mission Two',
          'net' => '2026-07-16T00:00:00Z',
          'rocket' => ['configuration' => ['name' => 'Falcon Heavy']],
          'mission' => ['name' => 'Demo', 'description' => 'A demo mission'],
        ],
      ],
    ];

    $httpClient->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        'https://ll.thespacedevs.com/2.3.0/launches/',
        $this->callback(static function (array $options): bool {
          return ($options['query']['limit'] ?? NULL) === 5
            && ($options['query']['offset'] ?? NULL) === 0;
        })
      )
      ->willReturn(new Response(200, [], json_encode($payload)));

    $service = new LaunchesApiService(
      $httpClient,
      $cacheBackend,
      $cacheInvalidator,
      $configFactory,
      $time,
      $logger,
    );

    $result = $service->getLaunches();

    $this->assertCount(2, $result->launches);
    $this->assertSame(2, $result->totalCount);
    $this->assertSame(5, $result->limit);
    $this->assertSame('Mission Two', $result->launches[0]['name']);
    $this->assertSame('Falcon Heavy', $result->launches[0]['rocket_name']);
  }

  /**
   * @covers ::getLaunches
   */
  public function testGetLaunchesHandlesRequestFailures(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $cacheBackend = $this->createMock(CacheBackendInterface::class);
    $cacheBackend->method('get')->willReturn(FALSE);

    $cacheInvalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $cacheInvalidator->expects($this->never())->method('invalidateTags');

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['cache_ttl_minutes', 15],
      ['results_limit', 5],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('spacex_launches.settings')->willReturn($config);

    $time = $this->createMock(TimeInterface::class);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $httpClient->expects($this->once())
      ->method('request')
      ->willThrowException(new RequestException('Timeout', new Request('GET', 'https://ll.thespacedevs.com/2.3.0/launches/')));

    $service = new LaunchesApiService(
      $httpClient,
      $cacheBackend,
      $cacheInvalidator,
      $configFactory,
      $time,
      $logger,
    );

    $result = $service->getLaunches(TRUE);

    $this->assertSame([], $result->launches);
    $this->assertSame('Launch data is temporarily unavailable.', $result->errorMessage);
  }

}
