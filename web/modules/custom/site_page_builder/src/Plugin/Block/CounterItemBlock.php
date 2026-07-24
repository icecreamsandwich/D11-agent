<?php

declare(strict_types=1);

namespace Drupal\site_page_builder\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides a Counter Item block for displaying a statistic with icon.
 */
#[Block(
  id: 'site_page_builder_counter_item',
  admin_label: new TranslatableMarkup('Counter Item'),
  category: new TranslatableMarkup('Site Page Builder'),
)]
final class CounterItemBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'icon'          => '',
      'number'        => '',
      'counter_label' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['icon'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Icon CSS Class'),
      '#default_value' => $this->configuration['icon'],
      '#description'   => $this->t('Font Awesome 6 class string, e.g. <code>fa-solid fa-briefcase</code>. Requires Font Awesome to be enabled in the Mahi theme settings.'),
    ];
    $form['number'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Number / Value'),
      '#default_value' => $this->configuration['number'],
      '#required'      => TRUE,
      '#description'   => $this->t('E.g. <em>250+</em> or <em>8+</em>.'),
    ];
    $form['counter_label'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Label'),
      '#default_value' => $this->configuration['counter_label'],
      '#required'      => TRUE,
      '#description'   => $this->t('E.g. <em>Projects Completed</em>.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['icon']          = $form_state->getValue('icon');
    $this->configuration['number']        = $form_state->getValue('number');
    $this->configuration['counter_label'] = $form_state->getValue('counter_label');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#theme'         => 'block__site_page_builder_counter_item_block',
      '#icon'          => $this->configuration['icon'],
      '#number'        => $this->configuration['number'],
      '#counter_label' => $this->configuration['counter_label'],
      '#attached'      => ['library' => ['site_page_builder/page_builder']],
      '#cache'         => ['max-age' => 3600],
    ];
  }

}
