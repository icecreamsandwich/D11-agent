<?php

declare(strict_types=1);

namespace Drupal\Tests\d11_performance_optimizer\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\d11_performance_optimizer\Service\CodingStandardsValidatorService;

/**
 * Unit tests for CodingStandardsValidatorService.
 *
 * @group d11_performance_optimizer
 * @coversDefaultClass \Drupal\d11_performance_optimizer\Service\CodingStandardsValidatorService
 */
final class CodingStandardsValidatorServiceTest extends UnitTestCase {

  private CodingStandardsValidatorService $service;

  private string $tmpDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $loggerChannel = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($loggerChannel);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $extensionList = $this->createMock(ExtensionList::class);

    $this->service = new CodingStandardsValidatorService(
      $loggerFactory,
      $configFactory,
      $moduleHandler,
      $extensionList,
    );

    $this->tmpDir = sys_get_temp_dir() . '/d11_test_' . uniqid();
    mkdir($this->tmpDir, 0755, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    parent::tearDown();
    if (is_dir($this->tmpDir)) {
      array_map('unlink', glob($this->tmpDir . '/*.php') ?: []);
      rmdir($this->tmpDir);
    }
  }

  /**
   * @covers ::validateFile
   */
  public function testDetectsMissingStrictTypes(): void {
    $file = $this->tmpDir . '/no_strict.php';
    file_put_contents($file, "<?php\nclass Foo {}\n");

    $issues = $this->service->validateFile($file);
    $types = array_column($issues, 'type');

    $this->assertContains('strict_types', $types, 'Should detect missing strict_types.');
  }

  /**
   * @covers ::validateFile
   */
  public function testDetectsStaticServiceUsage(): void {
    $file = $this->tmpDir . '/static_service.php';
    file_put_contents($file, "<?php\ndeclare(strict_types=1);\nnamespace Foo;\nclass Bar {\n  public function go(): void {\n    \\Drupal::service('foo');\n  }\n}\n");

    $issues = $this->service->validateFile($file);
    $types = array_column($issues, 'type');

    $this->assertContains('static_service', $types, 'Should detect \\Drupal::service() usage.');
  }

  /**
   * @covers ::validateFile
   */
  public function testDetectsLegacyDbFunctions(): void {
    $file = $this->tmpDir . '/legacy_db.php';
    file_put_contents($file, "<?php\ndeclare(strict_types=1);\n\$result = db_query('SELECT 1');\n");

    $issues = $this->service->validateFile($file);
    $types = array_column($issues, 'type');

    $this->assertContains('legacy_db', $types, 'Should detect db_query() usage.');
  }

  /**
   * @covers ::validateFile
   */
  public function testDetectsDeprecatedDrupalGetPath(): void {
    $file = $this->tmpDir . '/deprecated_api.php';
    file_put_contents($file, "<?php\ndeclare(strict_types=1);\n\$path = drupal_get_path('module', 'foo');\n");

    $issues = $this->service->validateFile($file);
    $types = array_column($issues, 'type');

    $this->assertContains('deprecated_api', $types, 'Should detect drupal_get_path() usage.');
  }

  /**
   * @covers ::validateFile
   */
  public function testCleanFileProducesNoIssues(): void {
    $file = $this->tmpDir . '/clean.php';
    file_put_contents($file, "<?php\ndeclare(strict_types=1);\nnamespace Drupal\\my_module\\Service;\nfinal class CleanService {}\n");

    $issues = $this->service->validateFile($file);
    $this->assertEmpty($issues, 'Clean file should produce no issues.');
  }

}
