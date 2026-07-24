<?php

declare(strict_types=1);

namespace Drupal\xhprof_poc\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\xhprof_poc\Profiler\ProfilerService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Starts profiling right after routing and saves the run after the response.
 *
 * Profiling is triggered on the demo route, or on ANY route when the request
 * carries ?xhprof_poc=1 (handy to profile existing pages).
 */
class ProfilerSubscriber implements EventSubscriberInterface {

  public const QUERY_FLAG = 'xhprof_poc';

  public function __construct(
    protected ProfilerService $profiler,
    protected RouteMatchInterface $routeMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Priority 28: after core routing (32), before dynamic_page_cache (27).
      KernelEvents::REQUEST => ['onRequest', 28],
      KernelEvents::TERMINATE => ['onTerminate', 300],
    ];
  }

  /**
   * Kicks off a profiling run when the request qualifies.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    $route_name = $this->routeMatch->getRouteName();
    $flagged = $request->query->get(self::QUERY_FLAG) === '1';

    if ($route_name === 'xhprof_poc.demo' || $flagged) {
      $request_start = $request->server->get('REQUEST_TIME_FLOAT');
      $this->profiler->startRun(
        $request->getRequestUri(),
        $request_start ? (float) $request_start : NULL,
      );
    }
  }

  /**
   * Persists the run after the response has been sent to the client.
   */
  public function onTerminate(TerminateEvent $event): void {
    if ($this->profiler->isActive()) {
      $this->profiler->endRun();
    }
  }

}
