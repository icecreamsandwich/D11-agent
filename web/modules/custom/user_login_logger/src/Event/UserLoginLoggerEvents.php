<?php

declare(strict_types=1);

namespace Drupal\user_login_logger\Event;

/**
 * Defines events for the User Login Logger module.
 */
final class UserLoginLoggerEvents {

  /**
   * The name of the event fired when a user logs in.
   *
   * @var string
   */
  public const USER_LOGIN = 'user_login_logger.user_login';

}
