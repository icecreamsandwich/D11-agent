<?php

declare(strict_types=1);

namespace Drupal\spacex_launches\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\spacex_launches\Service\LaunchesApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a SpaceX launches block.
 */
#[Block(
  id: 'spacex_launches_block',
  admin_label: new TranslatableMarkup('SpaceX launches'),
  category: new TranslatableMarkup('Custom'),
)]
final class SpacexLaunchesBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly LaunchesApiService $launchesApi,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly AccountInterface $currentUser,
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
      $container->get('date.formatter'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'endpoint' => '',
      'results_limit' => 3,
      'additional_query' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('API endpoint override'),
      '#description' => $this->t('Optional alternate endpoint. Leave empty to use the default launches endpoint.'),
      '#default_value' => $this->configuration['endpoint'],
    ];

    $form['results_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Result limit override'),
      '#description' => $this->t('How many launches to render in this block instance.'),
      '#default_value' => $this->configuration['results_limit'],
      '#min' => 1,
      '#max' => 20,
      '#required' => TRUE,
    ];

    $form['additional_query'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Additional query string'),
      '#description' => $this->t('Optional query parameters without the leading question mark, for example "search=SpaceX".'),
      '#default_value' => $this->configuration['additional_query'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['endpoint'] = trim((string) $form_state->getValue('endpoint'));
    $this->configuration['results_limit'] = (int) $form_state->getValue('results_limit');
    $this->configuration['additional_query'] = trim((string) $form_state->getValue('additional_query'));
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    if (!$this->currentUser->hasPermission('access spacex launches page')) {
      return [];
    }

    $result = $this->launchesApi->getLaunches(FALSE, [
      'endpoint' => $this->configuration['endpoint'] ?: NULL,
      'limit' => (int) $this->configuration['results_limit'],
      'query' => $this->parseAdditionalQuery((string) $this->configuration['additional_query']),
    ]);

    return [
      '#theme' => 'spacex_launches_page',
      '#launches' => $result->launches,
      '#last_fetched' => $result->fetchedAt ? $this->dateFormatter->format($result->fetchedAt, 'custom', 'Y-m-d H:i:s T') : NULL,
      '#error_message' => $result->errorMessage,
      '#refresh_url' => NULL,
      '#pager' => [],
      '#cache' => [
        'max-age' => $this->launchesApi->getCacheMaxAge(),
        'contexts' => ['user.permissions'],
        'tags' => ['config:spacex_launches.settings', $this->launchesApi->getRenderCacheTag()],
      ],
    ];
  }

  /**
   * Parses a block configuration query string into an array.
   *
   * @return array<string,string>
   *   Query values safe to send to the API.
   */
  private function parseAdditionalQuery(string $queryString): array {
    if ($queryString === '') {
      return [];
    }

    parse_str($queryString, $parsed);
    return array_filter($parsed, static fn ($value): bool => is_scalar($value) || $value === NULL);
  }

}
