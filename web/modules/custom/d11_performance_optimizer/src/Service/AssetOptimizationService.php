<?php

declare(strict_types=1);

namespace Drupal\d11_performance_optimizer\Service;

use Drupal\Core\Asset\AssetCollectionOptimizerInterface;
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Optimizes CSS and JavaScript asset delivery.
 */
class AssetOptimizationService {

  /**
   * Known render-blocking script patterns.
   *
   * @var array<int, string>
   */
  protected const BLOCKING_SCRIPT_PATTERNS = [
    'jquery.min.js',
    'bootstrap.min.js',
    'modernizr',
    'respond.min.js',
  ];

  /**
   * Constructs an AssetOptimizationService.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
    protected readonly LibraryDiscoveryInterface $libraryDiscovery,
    protected readonly AssetCollectionOptimizerInterface $cssOptimizer,
    protected readonly AssetCollectionOptimizerInterface $jsOptimizer,
  ) {}

  /**
   * Alters the page attachments to optimize asset loading.
   *
   * @param array<string, mixed> $attachments
   *   The page attachments array (passed by reference).
   */
  public function alterAttachments(array &$attachments): void {
    $config = $this->configFactory->get('d11_performance_optimizer.settings');

    if ($config->get('add_preload_hints')) {
      $this->addPreloadHints($attachments);
    }

    if ($config->get('detect_render_blocking')) {
      $this->detectAndFlagBlockingResources($attachments);
    }
  }

  /**
   * Adds resource preload and prefetch hints to the head.
   *
   * @param array<string, mixed> $attachments
   *   The page attachments array (passed by reference).
   */
  protected function addPreloadHints(array &$attachments): void {
    // Preload fonts if any are attached.
    if (!empty($attachments['#attached']['html_head'])) {
      $fontExtensions = ['woff2', 'woff', 'ttf'];
      foreach ($attachments['#attached']['html_head'] as $item) {
        if (
          isset($item[0]['#tag']) &&
          $item[0]['#tag'] === 'link' &&
          isset($item[0]['#attributes']['href'])
        ) {
          $href = $item[0]['#attributes']['href'];
          $ext = pathinfo($href, PATHINFO_EXTENSION);
          if (in_array($ext, $fontExtensions, TRUE)) {
            $attachments['#attached']['html_head'][] = [
              [
                '#tag' => 'link',
                '#attributes' => [
                  'rel' => 'preload',
                  'href' => $href,
                  'as' => 'font',
                  'crossorigin' => 'anonymous',
                ],
              ],
              'preload_font_' . md5($href),
            ];
          }
        }
      }
    }
  }

  /**
   * Scans attached libraries for potential render-blocking scripts.
   *
   * @param array<string, mixed> $attachments
   *   The page attachments array (passed by reference).
   */
  protected function detectAndFlagBlockingResources(array &$attachments): void {
    if (empty($attachments['#attached']['library'])) {
      return;
    }

    $blockingLibraries = [];

    foreach ($attachments['#attached']['library'] as $library) {
      foreach (self::BLOCKING_SCRIPT_PATTERNS as $pattern) {
        if (str_contains(strtolower($library), strtolower($pattern))) {
          $blockingLibraries[] = $library;
          break;
        }
      }
    }

    if (!empty($blockingLibraries)) {
      $this->loggerFactory->get('d11_performance_optimizer')->notice(
        'Potential render-blocking libraries detected: @libs',
        ['@libs' => implode(', ', $blockingLibraries)]
      );
    }
  }

  /**
   * Injects inline critical CSS placeholder.
   *
   * In a full implementation this would extract above-the-fold CSS.
   * Here we provide the hook point and add a note in the head.
   *
   * @param array<string, mixed> $pageFop
   *   The page_top render array (passed by reference).
   */
  public function injectCriticalCss(array &$pageFop): void {
    // This is the integration point for critical CSS extraction.
    // Production implementation would:
    // 1. Extract critical CSS via a headless browser tool (e.g., penthouse).
    // 2. Cache the critical CSS per route.
    // 3. Inline it here and defer the remaining stylesheet.
    $this->loggerFactory->get('d11_performance_optimizer')
      ->info('Critical CSS injection requested but no critical CSS file configured.');
  }

  /**
   * Analyzes the asset payload for a set of attachments.
   *
   * @param array<string, mixed> $attachments
   *   Page attachments.
   *
   * @return array<string, mixed>
   *   Analysis results.
   */
  public function analyzeAssetPayload(array $attachments): array {
    $libraryCount = count($attachments['#attached']['library'] ?? []);
    $htmlHeadCount = count($attachments['#attached']['html_head'] ?? []);

    $issues = [];

    if ($libraryCount > 20) {
      $issues[] = sprintf('High library count: %d libraries attached to this page.', $libraryCount);
    }

    return [
      'library_count' => $libraryCount,
      'html_head_count' => $htmlHeadCount,
      'issues' => $issues,
    ];
  }

}
