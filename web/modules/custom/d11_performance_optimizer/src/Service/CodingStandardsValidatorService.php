<?php

declare(strict_types=1);

namespace Drupal\d11_performance_optimizer\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Validates custom module code against Drupal coding standards.
 */
final class CodingStandardsValidatorService {

  public function __construct(
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ExtensionList $extensionList,
  ) {}

  /**
   * Validates all custom modules found under /modules/custom.
   *
   * @return array<string, mixed>
   *   Validation results keyed by module name.
   */
  public function validateCustomModules(): array {
    $results = [];
    $root = DRUPAL_ROOT;
    $customPath = $root . '/modules/custom';

    if (!is_dir($customPath)) {
      return $results;
    }

    $directories = glob($customPath . '/*', GLOB_ONLYDIR);
    if (!$directories) {
      return $results;
    }

    foreach ($directories as $dir) {
      $moduleName = basename($dir);
      // Skip this module itself to avoid recursion.
      if ($moduleName === 'd11_performance_optimizer') {
        continue;
      }
      $results[$moduleName] = $this->validateDirectory($dir);
    }

    return $results;
  }

  /**
   * Validates a single directory of PHP files.
   *
   * @param string $directory
   *   Absolute path to the directory.
   *
   * @return array<string, mixed>
   *   Issues found in the directory.
   */
  public function validateDirectory(string $directory): array {
    $issues = [];
    $files = $this->collectPhpFiles($directory);

    foreach ($files as $file) {
      $fileIssues = $this->validateFile($file);
      if (!empty($fileIssues)) {
        $issues[str_replace(DRUPAL_ROOT . '/', '', $file)] = $fileIssues;
      }
    }

    // Optionally run phpcs if available.
    $phpcsResult = $this->runPhpcs($directory);
    if ($phpcsResult) {
      $issues['_phpcs'] = $phpcsResult;
    }

    return $issues;
  }

  /**
   * Performs static analysis on a single PHP file.
   *
   * @param string $filePath
   *   Absolute path to the PHP file.
   *
   * @return array<int, array<string, string>>
   *   List of issues found.
   */
  public function validateFile(string $filePath): array {
    $issues = [];
    $content = @file_get_contents($filePath);
    if ($content === FALSE) {
      return $issues;
    }

    $filename = basename($filePath);

    // Detect missing strict_types declaration.
    if (preg_match('/\.php$/', $filename) && !str_contains($content, 'declare(strict_types=1)')) {
      $issues[] = [
        'type' => 'strict_types',
        'severity' => 'warning',
        'message' => 'Missing declare(strict_types=1) at top of file.',
      ];
    }

    // Detect static service usage (anti-pattern in Drupal 11).
    if (preg_match_all('/\\\\Drupal::service\(/', $content, $matches)) {
      $count = count($matches[0]);
      if ($count > 0) {
        $issues[] = [
          'type' => 'static_service',
          'severity' => 'warning',
          'message' => sprintf('Static \\Drupal::service() used %d time(s). Prefer dependency injection.', $count),
        ];
      }
    }

    // Detect direct database queries (non-DI).
    if (preg_match('/\bdb_query\b|\bdb_select\b|\bdb_insert\b/', $content)) {
      $issues[] = [
        'type' => 'legacy_db',
        'severity' => 'error',
        'message' => 'Legacy db_*() functions detected. Use the Database API via dependency injection.',
      ];
    }

    // Detect deprecated t() usage outside .module files in OOP classes.
    if (!preg_match('/\.(module|install|theme)$/', $filename)) {
      if (preg_match('/\bt\(/', $content) && preg_match('/class\s+\w+/', $content)) {
        $issues[] = [
          'type' => 'deprecated_t',
          'severity' => 'warning',
          'message' => 'Using global t() in a class. Use $this->t() or StringTranslationTrait.',
        ];
      }
    }

    // Detect use of drupal_get_path() (removed in Drupal 10+).
    if (preg_match('/\bdrupal_get_path\b/', $content)) {
      $issues[] = [
        'type' => 'deprecated_api',
        'severity' => 'error',
        'message' => 'drupal_get_path() is removed. Use the extension.list service or \Drupal::service(\'extension.list.module\')->getPath().',
      ];
    }

    // Detect node_load() / user_load() direct calls.
    if (preg_match('/\b(node_load|user_load|taxonomy_term_load)\b/', $content)) {
      $issues[] = [
        'type' => 'deprecated_loader',
        'severity' => 'warning',
        'message' => 'Procedural entity loaders detected. Use EntityTypeManager::getStorage() instead.',
      ];
    }

    // Detect PHP closing tag (PSR-12 violation).
    if (preg_match('/\?>\s*$/', $content)) {
      $issues[] = [
        'type' => 'closing_tag',
        'severity' => 'warning',
        'message' => 'PHP closing tag ?> found at end of file. Remove it per PSR-12.',
      ];
    }

    // Detect missing namespace in class files.
    if (preg_match('/class\s+\w+/', $content) && !preg_match('/^namespace\s+/m', $content)) {
      $issues[] = [
        'type' => 'missing_namespace',
        'severity' => 'error',
        'message' => 'Class file is missing a namespace declaration.',
      ];
    }

    return $issues;
  }

  /**
   * Collects all PHP files recursively in a directory.
   *
   * @param string $directory
   *   The directory to scan.
   *
   * @return array<int, string>
   *   List of absolute file paths.
   */
  private function collectPhpFiles(string $directory): array {
    $files = [];
    try {
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
      );
      foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
          $files[] = $file->getPathname();
        }
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('d11_performance_optimizer')
        ->warning('Could not scan directory @dir: @msg', [
          '@dir' => $directory,
          '@msg' => $e->getMessage(),
        ]);
    }
    return $files;
  }

  /**
   * Attempts to run PHPCS on a directory if it is available.
   *
   * @param string $directory
   *   Directory to run PHPCS on.
   *
   * @return string|null
   *   PHPCS output or NULL if not available.
   */
  private function runPhpcs(string $directory): ?string {
    $phpcs = trim((string) shell_exec('which phpcs 2>/dev/null'));
    if (empty($phpcs)) {
      return NULL;
    }

    $command = sprintf(
      '%s --standard=Drupal --extensions=php,module,inc,install,test,profile,theme,css,info --report=summary %s 2>&1',
      escapeshellcmd($phpcs),
      escapeshellarg($directory)
    );

    $output = shell_exec($command);
    return $output ?: NULL;
  }

}
