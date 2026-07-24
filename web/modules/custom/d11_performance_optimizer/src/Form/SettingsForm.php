<?php

declare(strict_types=1);

namespace Drupal\d11_performance_optimizer\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for the D11 Performance Optimizer module.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['d11_performance_optimizer.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'd11_performance_optimizer_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('d11_performance_optimizer.settings');

    $form['thresholds'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Performance Thresholds'),
    ];

    $form['thresholds']['slow_request_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Slow request threshold (ms)'),
      '#description' => $this->t('Requests taking longer than this value will be flagged as slow.'),
      '#default_value' => $config->get('slow_request_threshold'),
      '#min' => 100,
      '#max' => 30000,
      '#required' => TRUE,
    ];

    $form['thresholds']['slow_query_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Slow query threshold (ms)'),
      '#description' => $this->t('Database queries exceeding this value are logged.'),
      '#default_value' => $config->get('slow_query_threshold'),
      '#min' => 10,
      '#max' => 5000,
      '#required' => TRUE,
    ];

    $form['thresholds']['high_memory_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('High memory threshold (bytes)'),
      '#description' => $this->t('Default: 64MB (67108864). Requests exceeding this are logged.'),
      '#default_value' => $config->get('high_memory_threshold'),
      '#min' => 16777216,
      '#required' => TRUE,
    ];

    $form['thresholds']['large_response_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Large response threshold (bytes)'),
      '#description' => $this->t('Default: 1MB (1048576). Responses larger than this are logged.'),
      '#default_value' => $config->get('large_response_threshold'),
      '#min' => 102400,
      '#required' => TRUE,
    ];

    $form['thresholds']['sampling_rate'] = [
      '#type' => 'number',
      '#title' => $this->t('Metric sampling rate'),
      '#description' => $this->t('Record metrics 1 in every N requests. 1 = every request (high overhead), 10 = every 10th request (recommended for production).'),
      '#default_value' => $config->get('sampling_rate'),
      '#min' => 1,
      '#max' => 100,
      '#required' => TRUE,
    ];

    $form['features'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Feature Toggles'),
    ];

    $features = [
      'enable_css_optimization' => $this->t('Enable CSS optimization (defer/preload hints)'),
      'enable_js_optimization' => $this->t('Enable JavaScript optimization (defer render-blocking scripts)'),
      'enable_seo_optimization' => $this->t('Enable SEO optimization (auto-inject canonical, robots, og:type)'),
      'enable_render_optimization' => $this->t('Enable render pipeline analysis'),
      'enable_query_monitoring' => $this->t('Enable database query monitoring'),
      'enable_coding_standards' => $this->t('Enable coding standards validation (may be slow on large codebases)'),
      'enable_performance_logging' => $this->t('Enable performance event logging'),
    ];

    foreach ($features as $key => $label) {
      $form['features'][$key] = [
        '#type' => 'checkbox',
        '#title' => $label,
        '#default_value' => $config->get($key),
      ];
    }

    $form['js'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('JavaScript Optimization'),
      '#states' => [
        'visible' => [':input[name="enable_js_optimization"]' => ['checked' => TRUE]],
      ],
    ];

    $form['js']['defer_javascript'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Automatically add defer attribute to render-blocking scripts in <head>'),
      '#default_value' => $config->get('defer_javascript'),
    ];

    $form['js']['lazy_load_javascript'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable lazy loading for non-critical JavaScript'),
      '#default_value' => $config->get('lazy_load_javascript'),
    ];

    $form['cache'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Cache Analysis'),
    ];

    $form['cache']['cache_hit_ratio_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache hit ratio alert threshold (0.0 – 1.0)'),
      '#description' => $this->t('Generate a recommendation when cache hit ratio drops below this value. Default: 0.8 (80%).'),
      '#default_value' => $config->get('cache_hit_ratio_threshold'),
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.05,
      '#required' => TRUE,
    ];

    $form['retention'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Data Retention'),
    ];

    $form['retention']['log_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Log retention (days)'),
      '#description' => $this->t('Performance log entries older than this are deleted on cron.'),
      '#default_value' => $config->get('log_retention_days'),
      '#min' => 1,
      '#max' => 365,
      '#required' => TRUE,
    ];

    $form['retention']['metrics_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Metrics retention (days)'),
      '#description' => $this->t('Performance metric rows older than this are deleted on cron.'),
      '#default_value' => $config->get('metrics_retention_days'),
      '#min' => 1,
      '#max' => 90,
      '#required' => TRUE,
    ];

    $form['recommendations'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Recommendations'),
    ];

    $form['recommendations']['max_recommendations'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum recommendations shown'),
      '#default_value' => $config->get('max_recommendations'),
      '#min' => 1,
      '#max' => 50,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('d11_performance_optimizer.settings')
      ->set('slow_request_threshold', (int) $form_state->getValue('slow_request_threshold'))
      ->set('slow_query_threshold', (int) $form_state->getValue('slow_query_threshold'))
      ->set('high_memory_threshold', (int) $form_state->getValue('high_memory_threshold'))
      ->set('large_response_threshold', (int) $form_state->getValue('large_response_threshold'))
      ->set('sampling_rate', (int) $form_state->getValue('sampling_rate'))
      ->set('enable_css_optimization', (bool) $form_state->getValue('enable_css_optimization'))
      ->set('enable_js_optimization', (bool) $form_state->getValue('enable_js_optimization'))
      ->set('enable_seo_optimization', (bool) $form_state->getValue('enable_seo_optimization'))
      ->set('enable_render_optimization', (bool) $form_state->getValue('enable_render_optimization'))
      ->set('enable_query_monitoring', (bool) $form_state->getValue('enable_query_monitoring'))
      ->set('enable_coding_standards', (bool) $form_state->getValue('enable_coding_standards'))
      ->set('enable_performance_logging', (bool) $form_state->getValue('enable_performance_logging'))
      ->set('defer_javascript', (bool) $form_state->getValue('defer_javascript'))
      ->set('lazy_load_javascript', (bool) $form_state->getValue('lazy_load_javascript'))
      ->set('cache_hit_ratio_threshold', (float) $form_state->getValue('cache_hit_ratio_threshold'))
      ->set('log_retention_days', (int) $form_state->getValue('log_retention_days'))
      ->set('metrics_retention_days', (int) $form_state->getValue('metrics_retention_days'))
      ->set('max_recommendations', (int) $form_state->getValue('max_recommendations'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
