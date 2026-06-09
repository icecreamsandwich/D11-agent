<?php

namespace Drupal\d11_multilingual\EventSubscriber;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects bare root requests (/) to the configured default language prefix.
 *
 * Without this, a visitor hitting example.com/ would land on an unlocalized
 * path. The URL negotiation plugin handles /en/* correctly but the root path
 * needs an explicit redirect to /en to trigger detection.
 */
class LanguageRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected LanguageManagerInterface $languageManager,
    protected AccountInterface $currentUser,
    protected CurrentPathStack $currentPath,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
    ];
  }

  /**
   * Redirects / to the default language prefix if no prefix is detected.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    $path = $request->getPathInfo();

    if ($path !== '/') {
      return;
    }

    $default_lang = $this->languageManager->getDefaultLanguage()->getId();
    $response = new \Symfony\Component\HttpFoundation\RedirectResponse(
      $request->getBaseUrl() . '/' . $default_lang,
      301
    );
    $event->setResponse($response);
  }

}
