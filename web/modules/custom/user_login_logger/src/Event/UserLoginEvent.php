<?php

declare(strict_types=1);

namespace Drupal\user_login_logger\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\user\UserInterface;

/**
 * Event object for a successful user login.
 */
final class UserLoginEvent extends Event {

  /**
   * Constructs a user login event.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account that logged in.
   */
  public function __construct(
    private readonly UserInterface $account,
  ) {}

  /**
   * Returns the account that logged in.
   *
   * @return \Drupal\user\UserInterface
   *   The user account.
   */
  public function getAccount(): UserInterface {
    return $this->account;
  }

}
