<?php

declare(strict_types=1);

namespace Drupal\d11_performance_optimizer\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\d11_performance_optimizer\Service\PerformanceLoggerService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subscriber that analyzes render pipeline performance on each response.
 */
final class RenderOptimizationSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly PerformanceLoggerService $performanceLogger,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => [['onResponse', 10]],
    ];
  }

  /**
   * Analyzes the response for render-related performance issues.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $config = $this->configFactory->get('d11_performance_optimizer.settings');
    if (!$config->get('enable_render_optimization')) {
      return;
    }

    $response = $event->getResponse();
    $path = $event->getRequest()->getPathInfo();

    // Skip non-HTML responses.
    $contentType = (string) $response->headers->get('Content-Type', '');
    if (!str_contains($contentType, 'text/html')) {
      return;
    }

    // Check X-Drupal-Cache headers to detect cache miss patterns.
    $drupalCache = $response->headers->get('X-Drupal-Cache');
    $dynamicCache = $response->headers->get('X-Drupal-Dynamic-Cache');

    if ($drupalCache === 'MISS' || $dynamicCache === 'UNCACHEABLE') {
      $this->performanceLogger->log(
        PerformanceLoggerService::TYPE_CACHE_MISS,
        sprintf(
          'Page cache miss on %s. Drupal-Cache: %s, Dynamic-Cache: %s',
          $path,
          $drupalCache ?? 'N/A',
          $dynamicCache ?? 'N/A',
        ),
        $path,
        'info',
        [
          'x_drupal_cache' => $drupalCache,
          'x_drupal_dynamic_cache' => $dynamicCache,
        ],
      );
    }

    // Check for very large responses that indicate over-rendering.
    $content = $response->getContent();
    if ($content !== FALSE) {
      $sizeKb = strlen($content) / 1024;
      if ($sizeKb > 500) {
        $this->loggerFactory->get('d11_performance_optimizer')->warning(
          'Large HTML response (@sizeKb KB) on @path — possible over-rendering.',
          ['@sizeKb' => round($sizeKb, 1), '@path' => $path]
        );
      }
    }
  }

}
