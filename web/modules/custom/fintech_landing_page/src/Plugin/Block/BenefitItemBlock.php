<?php

declare(strict_types=1);

namespace Drupal\fintech_landing_page\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides a Benefit Item block for a single business benefit.
 */
#[Block(
  id: 'fintech_benefit_item',
  admin_label: new TranslatableMarkup('FinTech Benefit Item'),
  category: new TranslatableMarkup('FinTech Landing Page'),
)]
final class BenefitItemBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'icon'        => '',
      'title'       => '',
      'description' => '',
      'highlight'   => '',
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
      '#description'   => $this->t('Font Awesome 6 class string, e.g. <code>fa-solid fa-clock</code>.'),
    ];
    $form['title'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Benefit Title'),
      '#default_value' => $this->configuration['title'],
      '#required'      => TRUE,
      '#description'   => $this->t('E.g. <em>Save Time</em>.'),
    ];
    $form['description'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Description'),
      '#default_value' => $this->configuration['description'],
      '#rows'          => 3,
      '#required'      => TRUE,
      '#description'   => $this->t('Supporting explanation of the benefit.'),
    ];
    $form['highlight'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Highlight Metric'),
      '#default_value' => $this->configuration['highlight'],
      '#description'   => $this->t('Optional callout stat, e.g. <em>40% faster processing</em>.'),
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
    $this->configuration['highlight']   = $form_state->getValue('highlight');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#theme'       => 'block__fintech_benefit_item_block',
      '#icon'        => $this->configuration['icon'],
      '#title_text'  => $this->configuration['title'],
      '#description' => $this->configuration['description'],
      '#highlight'   => $this->configuration['highlight'],
      '#attached'    => ['library' => ['fintech_landing_page/fintech_page']],
      '#cache'       => ['max-age' => 3600],
    ];
  }

}
