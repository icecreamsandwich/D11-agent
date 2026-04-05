<?php

declare(strict_types=1);

namespace Drupal\d11_performance_optimizer\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\d11_performance_optimizer\Service\PerformanceAnalyzerService;
use Drupal\d11_performance_optimizer\Service\PerformanceLoggerService;
use Drupal\d11_performance_optimizer\Service\SEOOptimizationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Measures request lifecycle performance and triggers logging.
 */
final class RequestPerformanceSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly PerformanceAnalyzerService $performanceAnalyzer,
    private readonly PerformanceLoggerService $performanceLogger,
    private readonly SEOOptimizationService $seoOptimization,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => [['onKernelRequest', 100]],
      KernelEvents::RESPONSE => [['onKernelResponse', -100]],
    ];
  }

  /**
   * Starts performance timing on incoming requests.
   */
  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $path = $event->getRequest()->getPathInfo();
    // Skip admin and internal paths to reduce noise.
    if (str_starts_with($path, '/admin') || str_starts_with($path, '/_')) {
      return;
    }

    $this->performanceAnalyzer->beginRequest($path);
  }

  /**
   * Ends performance timing, triggers logging, and injects SEO analysis.
   */
  public function onKernelResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $response = $event->getResponse();
    $request = $event->getRequest();
    $path = $request->getPathInfo();

    if (str_starts_with($path, '/admin') || str_starts_with($path, '/_')) {
      return;
    }

    $config = $this->configFactory->get('d11_performance_optimizer.settings');
    $content = $response->getContent();
    $responseSize = $content !== FALSE ? strlen($content) : 0;

    $this->performanceAnalyzer->endRequest($responseSize);

    // Check thresholds for logging.
    $memoryUsage = memory_get_peak_usage(TRUE);
    $highMemoryThreshold = (int) $config->get('high_memory_threshold');
    if ($memoryUsage > $highMemoryThreshold) {
      $this->performanceLogger->logHighMemory($path, $memoryUsage);
    }

    $largeResponseThreshold = (int) $config->get('large_response_threshold');
    if ($responseSize > $largeResponseThreshold) {
      $this->performanceLogger->logLargeResponse($path, $responseSize);
    }

    // Run SEO HTML analysis on text/html responses.
    if ($content !== FALSE
      && $config->get('enable_seo_optimization')
      && str_contains((string) $response->headers->get('Content-Type', ''), 'text/html')
    ) {
      $issues = $this->seoOptimization->analyzeHtml($content);
      if (!empty($issues)) {
        \Drupal::logger('d11_performance_optimizer')->info(
          'SEO HTML analysis found @count issue(s) on @path.',
          ['@count' => count($issues), '@path' => $path]
        );
      }
    }
  }

}
