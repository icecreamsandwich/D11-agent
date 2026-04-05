<?php

declare(strict_types=1);

namespace Drupal\Tests\d11_performance_optimizer\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for the admin dashboard access and rendering.
 *
 * @group d11_performance_optimizer
 */
final class AdminDashboardAccessTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'dynamic_page_cache',
    'page_cache',
    'd11_performance_optimizer',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that anonymous users cannot access the dashboard.
   */
  public function testAnonymousAccessDenied(): void {
    $this->drupalGet('/admin/reports/performance-optimizer');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests that users without permission cannot access the dashboard.
   */
  public function testUnprivilegedUserAccessDenied(): void {
    $account = $this->drupalCreateUser([]);
    $this->drupalLogin($account);
    $this->drupalGet('/admin/reports/performance-optimizer');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests that privileged users can access the dashboard.
   */
  public function testPrivilegedUserCanAccessDashboard(): void {
    $account = $this->drupalCreateUser(['view performance optimizer dashboard']);
    $this->drupalLogin($account);
    $this->drupalGet('/admin/reports/performance-optimizer');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Overview');
  }

  /**
   * Tests that the settings page is only accessible to admins.
   */
  public function testSettingsPageAccess(): void {
    $viewer = $this->drupalCreateUser(['view performance optimizer dashboard']);
    $this->drupalLogin($viewer);
    $this->drupalGet('/admin/config/system/performance-optimizer');
    $this->assertSession()->statusCodeEquals(403);

    $admin = $this->drupalCreateUser(['administer performance optimizer']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/config/system/performance-optimizer');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('slow_query_threshold');
  }

  /**
   * Tests sub-pages are accessible to privileged users.
   */
  public function testSubPagesAccessible(): void {
    $account = $this->drupalCreateUser(['view performance optimizer dashboard']);
    $this->drupalLogin($account);

    $routes = [
      '/admin/reports/performance-optimizer/performance',
      '/admin/reports/performance-optimizer/database',
      '/admin/reports/performance-optimizer/assets',
      '/admin/reports/performance-optimizer/seo',
      '/admin/reports/performance-optimizer/coding-standards',
    ];

    foreach ($routes as $route) {
      $this->drupalGet($route);
      $this->assertSession()->statusCodeEquals(200, "Route $route should return 200.");
    }
  }

  /**
   * Tests that the settings form saves correctly.
   */
  public function testSettingsFormSaves(): void {
    $admin = $this->drupalCreateUser([
      'administer performance optimizer',
      'administer site configuration',
    ]);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/config/system/performance-optimizer');
    $this->submitForm([
      'slow_query_threshold' => 200,
      'slow_request_threshold' => 3000,
      'sampling_rate' => 5,
      'enable_seo_optimization' => TRUE,
      'enable_query_monitoring' => TRUE,
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $config = \Drupal::config('d11_performance_optimizer.settings');
    $this->assertEquals(200, $config->get('slow_query_threshold'));
    $this->assertEquals(3000, $config->get('slow_request_threshold'));
    $this->assertEquals(5, $config->get('sampling_rate'));
  }

}
