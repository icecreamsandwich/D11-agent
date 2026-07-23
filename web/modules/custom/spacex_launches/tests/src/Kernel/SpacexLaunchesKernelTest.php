<?php

declare(strict_types=1);

namespace Drupal\Tests\spacex_launches\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel tests for route and config integration.
 *
 * @group spacex_launches
 */
#[RunTestsInSeparateProcesses]
final class SpacexLaunchesKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'spacex_launches',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['spacex_launches']);
  }

  /**
   * Tests default configuration values.
   */
  public function testDefaultConfigIsInstalled(): void {
    $config = $this->config('spacex_launches.settings');

    $this->assertSame(15, $config->get('cache_ttl_minutes'));
    $this->assertSame(5, $config->get('results_limit'));
    $this->assertSame(15, $config->get('cron_refresh_interval_minutes'));
  }

  /**
   * Tests route definitions are available.
   */
  public function testRoutesExist(): void {
    $routeProvider = $this->container->get('router.route_provider');

    $pageRoute = $routeProvider->getRouteByName('spacex_launches.page');
    $settingsRoute = $routeProvider->getRouteByName('spacex_launches.settings');
    $refreshRoute = $routeProvider->getRouteByName('spacex_launches.refresh');

    $this->assertSame('/spacex-launches', $pageRoute->getPath());
    $this->assertSame('/admin/config/services/spacex-launches', $settingsRoute->getPath());
    $this->assertSame('/spacex-launches/refresh', $refreshRoute->getPath());
  }

}
