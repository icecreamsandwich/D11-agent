<?php

declare(strict_types=1);

namespace Drupal\user_login_logger\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\user_login_logger\Event\UserLoginEvent;
use Drupal\user_login_logger\Event\UserLoginLoggerEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Logs user login details to the Drupal log.
 */
final class UserLoginSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a UserLoginSubscriber.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory service.
   */
  public function __construct(
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      UserLoginLoggerEvents::USER_LOGIN => ['onUserLogin'],
    ];
  }

  /**
   * Logs the username and user ID when a user logs in.
   *
   * @param \Drupal\user_login_logger\Event\UserLoginEvent $event
   *   The user login event.
   */
  public function onUserLogin(UserLoginEvent $event): void {
    $account = $event->getAccount();
    $this->loggerFactory->get('user_login_logger')->info(
      'User %name logged in with uid @uid.',
      [
        '%name' => $account->getAccountName(),
        '@uid' => $account->id(),
      ],
    );
  }

}
