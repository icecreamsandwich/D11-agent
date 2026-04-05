<?php

declare(strict_types=1);

namespace Drupal\site_page_builder\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides a Hero Slider block.
 */
#[Block(
  id: 'site_page_builder_hero_slider',
  admin_label: new TranslatableMarkup('Hero Slider'),
  category: new TranslatableMarkup('Site Page Builder'),
)]
final class HeroSliderBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'image_url'   => '',
      'title'       => '',
      'subtitle'    => '',
      'button_text' => '',
      'button_link' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['image_url'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Background Image URL'),
      '#default_value' => $this->configuration['image_url'],
      '#description'   => $this->t('Enter an absolute URL or a relative path (e.g. /sites/default/files/hero.jpg).'),
    ];
    $form['title'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Heading'),
      '#default_value' => $this->configuration['title'],
      '#required'      => TRUE,
    ];
    $form['subtitle'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Subtitle / Description'),
      '#default_value' => $this->configuration['subtitle'],
      '#rows'          => 3,
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
      '#description'   => $this->t('Enter a relative path (e.g. /about-us) or anchor (#section).'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['image_url']   = $form_state->getValue('image_url');
    $this->configuration['title']       = $form_state->getValue('title');
    $this->configuration['subtitle']    = $form_state->getValue('subtitle');
    $this->configuration['button_text'] = $form_state->getValue('button_text');
    $this->configuration['button_link'] = $form_state->getValue('button_link');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#theme'      => 'block__site_page_builder_hero_slider_block',
      '#image_url'  => $this->configuration['image_url'],
      '#title_text' => $this->configuration['title'],
      '#subtitle'   => $this->configuration['subtitle'],
      '#button_text' => $this->configuration['button_text'],
      '#button_link' => $this->configuration['button_link'],
      '#attached'   => ['library' => ['site_page_builder/page_builder']],
      '#cache'      => ['max-age' => 3600],
    ];
  }

}
