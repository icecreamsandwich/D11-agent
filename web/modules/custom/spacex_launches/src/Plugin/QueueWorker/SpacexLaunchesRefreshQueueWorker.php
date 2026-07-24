<?php

declare(strict_types=1);

namespace Drupal\spacex_launches\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\State\StateInterface;
use Drupal\spacex_launches\Service\LaunchesApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued launch refresh jobs.
 */
#[QueueWorker(
  id: 'spacex_launches_refresh',
  title: new \Drupal\Core\StringTranslation\TranslatableMarkup('SpaceX launches refresh'),
  cron: ['time' => 30],
)]
final class SpacexLaunchesRefreshQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly LaunchesApiService $launchesApi,
    private readonly StateInterface $state,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('spacex_launches.api'),
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $this->launchesApi->invalidateCache();
    $result = $this->launchesApi->getLaunches(TRUE);

    if ($result->errorMessage === NULL) {
      $this->state->set('spacex_launches.last_refresh_time', $result->fetchedAt);
    }
  }

}
