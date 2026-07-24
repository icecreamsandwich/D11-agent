<?php

declare(strict_types=1);

namespace Drupal\site_page_builder\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides a Call to Action block.
 */
#[Block(
  id: 'site_page_builder_call_to_action',
  admin_label: new TranslatableMarkup('Call to Action'),
  category: new TranslatableMarkup('Site Page Builder'),
)]
final class CallToActionBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'title'       => '',
      'subtitle'    => '',
      'button_text' => '',
      'button_link' => '',
      'bg_color'    => '#2563EB',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['title'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Heading'),
      '#default_value' => $this->configuration['title'],
      '#required'      => TRUE,
    ];
    $form['subtitle'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Subtitle'),
      '#default_value' => $this->configuration['subtitle'],
      '#rows'          => 2,
    ];
    $form['button_text'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Button Text'),
      '#default_value' => $this->configuration['button_text'],
    ];
    $form['button_link'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Button Link'),
      '#default_value' => $this->configuration['button_link'],
      '#description'   => $this->t('Enter a relative path (e.g. /contact-us).'),
    ];
    $form['bg_color'] = [
      '#type'          => 'color',
      '#title'         => $this->t('Background Colour'),
      '#default_value' => $this->configuration['bg_color'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['title']       = $form_state->getValue('title');
    $this->configuration['subtitle']    = $form_state->getValue('subtitle');
    $this->configuration['button_text'] = $form_state->getValue('button_text');
    $this->configuration['button_link'] = $form_state->getValue('button_link');
    $this->configuration['bg_color']    = $form_state->getValue('bg_color');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#theme'       => 'block__site_page_builder_call_to_action_block',
      '#title_text'  => $this->configuration['title'],
      '#subtitle'    => $this->configuration['subtitle'],
      '#button_text' => $this->configuration['button_text'],
      '#button_link' => $this->configuration['button_link'],
      '#bg_color'    => $this->configuration['bg_color'],
      '#attached'    => ['library' => ['site_page_builder/page_builder']],
      '#cache'       => ['max-age' => 3600],
    ];
  }

}
