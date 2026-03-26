<?php

declare(strict_types=1);

namespace Drupal\d11_performance_optimizer\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\path_alias\AliasManagerInterface;

/**
 * Provides automatic SEO enhancements for page attachments.
 */
final class SEOOptimizationService {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly AliasManagerInterface $aliasManager,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Processes page attachments to inject and validate SEO metadata.
   *
   * @param array<string, mixed> $attachments
   *   The page attachments array, passed by reference.
   */
  public function processAttachments(array &$attachments): void {
    $config = $this->configFactory->get('d11_performance_optimizer.settings');
    if (!$config->get('enable_seo_optimization')) {
      return;
    }

    $head = &$attachments['#attached']['html_head'];
    if (!is_array($head)) {
      $head = [];
    }

    $existingMeta = $this->indexExistingMeta($head);
    $issues = [];

    // Ensure robots meta exists.
    if (!isset($existingMeta['robots'])) {
      $head[] = [
        [
          '#tag' => 'meta',
          '#attributes' => [
            'name' => 'robots',
            'content' => 'index, follow',
          ],
        ],
        'd11_seo_robots',
      ];
    }

    // Add canonical URL if not present.
    $hasCanonical = FALSE;
    foreach ($head as $element) {
      if (isset($element[0]['#attributes']['rel']) && $element[0]['#attributes']['rel'] === 'canonical') {
        $hasCanonical = TRUE;
        break;
      }
    }

    if (!$hasCanonical) {
      try {
        $currentPath = \Drupal::service('path.current')->getPath();
        $alias = $this->aliasManager->getAliasByPath($currentPath);
        $base = \Drupal::request()->getSchemeAndHttpHost();
        $canonicalUrl = $base . $alias;
        $head[] = [
          [
            '#tag' => 'link',
            '#attributes' => [
              'rel' => 'canonical',
              'href' => $canonicalUrl,
            ],
          ],
          'd11_seo_canonical',
        ];
      }
      catch (\Exception $e) {
        // Non-critical; skip canonical on error.
      }
    }

    // Add OpenGraph type if missing.
    if (!isset($existingMeta['og:type'])) {
      $head[] = [
        [
          '#tag' => 'meta',
          '#attributes' => [
            'property' => 'og:type',
            'content' => 'website',
          ],
        ],
        'd11_seo_og_type',
      ];
    }

    // Add language alternate link.
    $language = $this->languageManager->getCurrentLanguage();
    $head[] = [
      [
        '#tag' => 'meta',
        '#attributes' => [
          'http-equiv' => 'content-language',
          'content' => $language->getId(),
        ],
      ],
      'd11_seo_content_language',
    ];

    // Detect missing description.
    if (!isset($existingMeta['description'])) {
      $issues[] = 'Missing meta description — add one via Metatag module or node field.';
    }

    // Detect missing og:title.
    if (!isset($existingMeta['og:title'])) {
      $issues[] = 'Missing og:title OpenGraph tag.';
    }

    if (!empty($issues)) {
      $this->loggerFactory->get('d11_performance_optimizer')
        ->info('SEO issues detected on page: @issues', ['@issues' => implode('; ', $issues)]);
    }
  }

  /**
   * Builds an index of existing meta tag names/properties in <head>.
   *
   * @param array<int, mixed> $head
   *   The html_head attachments array.
   *
   * @return array<string, bool>
   */
  private function indexExistingMeta(array $head): array {
    $index = [];
    foreach ($head as $element) {
      if (!isset($element[0]['#attributes'])) {
        continue;
      }
      $attrs = $element[0]['#attributes'];
      if (isset($attrs['name'])) {
        $index[(string) $attrs['name']] = TRUE;
      }
      if (isset($attrs['property'])) {
        $index[(string) $attrs['property']] = TRUE;
      }
    }
    return $index;
  }

  /**
   * Analyzes an HTML string for SEO issues.
   *
   * @param string $html
   *   The HTML response body.
   *
   * @return array<int, array<string, string>>
   *   List of detected SEO issues.
   */
  public function analyzeHtml(string $html): array {
    $issues = [];

    // Check for title tag.
    if (!preg_match('/<title[^>]*>[^<]{1,}<\/title>/i', $html)) {
      $issues[] = ['severity' => 'error', 'issue' => 'Missing or empty <title> tag.'];
    }
    elseif (preg_match('/<title[^>]*>(.{70,})<\/title>/i', $html, $matches)) {
      $issues[] = [
        'severity' => 'warning',
        'issue' => sprintf('Title tag is too long (%d chars). Recommended max: 60-70.', strlen($matches[1])),
      ];
    }

    // Check for meta description.
    if (!preg_match('/<meta[^>]+name=["\']description["\'][^>]+>/i', $html)) {
      $issues[] = ['severity' => 'warning', 'issue' => 'Missing meta description tag.'];
    }

    // Detect images missing alt attributes.
    preg_match_all('/<img[^>]+>/i', $html, $imgMatches);
    foreach ($imgMatches[0] as $img) {
      if (!preg_match('/\balt\s*=/i', $img)) {
        $issues[] = ['severity' => 'warning', 'issue' => 'Image missing alt attribute: ' . substr($img, 0, 100)];
        break; // Report first occurrence; avoid flooding.
      }
    }

    // Check for canonical link.
    if (!preg_match('/<link[^>]+rel=["\']canonical["\'][^>]*>/i', $html)) {
      $issues[] = ['severity' => 'warning', 'issue' => 'No canonical link tag found.'];
    }

    // Check for duplicate meta description.
    preg_match_all('/<meta[^>]+name=["\']description["\'][^>]+>/i', $html, $descMatches);
    if (count($descMatches[0]) > 1) {
      $issues[] = ['severity' => 'error', 'issue' => 'Duplicate meta description tags detected.'];
    }

    return $issues;
  }

}
