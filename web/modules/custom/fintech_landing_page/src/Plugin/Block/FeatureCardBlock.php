<?php

declare(strict_types=1);

namespace Drupal\fintech_landing_page\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides a Feature Card block for a single platform feature.
 */
#[Block(
  id: 'fintech_feature_card',
  admin_label: new TranslatableMarkup('FinTech Feature Card'),
  category: new TranslatableMarkup('FinTech Landing Page'),
)]
final class FeatureCardBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'icon'        => '',
      'title'       => '',
      'description' => '',
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
      '#description'   => $this->t('Font Awesome 6 class string, e.g. <code>fa-solid fa-bolt</code>.'),
    ];
    $form['title'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Feature Title'),
      '#default_value' => $this->configuration['title'],
      '#required'      => TRUE,
      '#description'   => $this->t('E.g. <em>Instant Payments</em>.'),
    ];
    $form['description'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Description'),
      '#default_value' => $this->configuration['description'],
      '#rows'          => 3,
      '#required'      => TRUE,
      '#description'   => $this->t('One or two sentences describing the feature.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['icon']        = $form_state->getValue('icon');
    $this->configuration['title']       = $form_state->getValue('title');
    $this->configuration['description'] = $form_state->getValue('description');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#theme'       => 'block__fintech_feature_card_block',
      '#icon'        => $this->configuration['icon'],
      '#title_text'  => $this->configuration['title'],
      '#description' => $this->configuration['description'],
      '#attached'    => ['library' => ['fintech_landing_page/fintech_page']],
      '#cache'       => ['max-age' => 3600],
    ];
  }

}
