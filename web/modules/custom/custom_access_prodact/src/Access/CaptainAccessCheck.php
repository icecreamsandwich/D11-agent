<?php

declare(strict_types=1);

namespace Drupal\custom_access_prodact\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Access check that only allows users with the "captain" role.
 */
class CaptainAccessCheck {

  /**
   * Checks access for the current user.
   */
  public function access(AccountInterface $account) {
    if ($account->hasPermission('access content') && in_array('captain', $account->getRoles())) {
      return AccessResult::allowed()->addCacheContexts(['user.roles']);
    }
    return AccessResult::forbidden()->addCacheContexts(['user.roles']);
  }

}
