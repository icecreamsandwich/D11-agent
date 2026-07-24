<?php

declare(strict_types=1);

namespace Drupal\custom_access_prodact\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Returns responses for Custom access prodact routes.
 */
final class CustomAccessProdactController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function __invoke(): array {

    $build['content'] = [
      '#type' => 'item',
      '#markup' => $this->t('It works!'),
    ];

    return $build;
  }

  /**
   * Page restricted to users with the captain role.
   */
  public function captainPage() {
    return [
      '#markup' => $this->t('Welcome, Captain!'),
    ];
  }

  /**
   * Renders the custom themed page.
   */
  public function content() {
    return [
      '#theme' => 'custom_page',
      '#data' => 'hello world',
    ];
  }

}
