<?php

declare(strict_types=1);

namespace Drupal\spacex_launches\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for SpaceX launches settings.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['spacex_launches.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'spacex_launches_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('spacex_launches.settings');

    $form['cache_ttl_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache TTL in minutes'),
      '#description' => $this->t('How long API responses should be cached before Drupal fetches fresh launch data.'),
      '#default_value' => $config->get('cache_ttl_minutes') ?? 15,
      '#min' => 1,
      '#max' => 1440,
      '#required' => TRUE,
    ];

    $form['results_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of launches to display'),
      '#description' => $this->t('How many launches should be rendered on the public page.'),
      '#default_value' => $config->get('results_limit') ?? 5,
      '#min' => 1,
      '#max' => 100,
      '#required' => TRUE,
    ];

    $form['cron_refresh_interval_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Cron refresh interval in minutes'),
      '#description' => $this->t('How often cron should queue a background refresh.'),
      '#default_value' => $config->get('cron_refresh_interval_minutes') ?? 15,
      '#min' => 1,
      '#max' => 1440,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('spacex_launches.settings')
      ->set('cache_ttl_minutes', (int) $form_state->getValue('cache_ttl_minutes'))
      ->set('results_limit', (int) $form_state->getValue('results_limit'))
      ->set('cron_refresh_interval_minutes', (int) $form_state->getValue('cron_refresh_interval_minutes'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
