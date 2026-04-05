<?php

declare(strict_types=1);

namespace Drupal\fintech_landing_page\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides a Testimonial Card block for a single customer quote.
 */
#[Block(
  id: 'fintech_testimonial_card',
  admin_label: new TranslatableMarkup('FinTech Testimonial Card'),
  category: new TranslatableMarkup('FinTech Landing Page'),
)]
final class TestimonialCardBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'quote'       => '',
      'author_name' => '',
      'author_role' => '',
      'rating'      => 5,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['quote'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Testimonial Quote'),
      '#default_value' => $this->configuration['quote'],
      '#rows'          => 4,
      '#required'      => TRUE,
      '#description'   => $this->t('The customer\'s testimonial in their own words.'),
    ];
    $form['author_name'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Author Name'),
      '#default_value' => $this->configuration['author_name'],
      '#required'      => TRUE,
      '#description'   => $this->t('E.g. <em>Ahmed Al-Rashidi</em>.'),
    ];
    $form['author_role'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Author Role / Business'),
      '#default_value' => $this->configuration['author_role'],
      '#required'      => TRUE,
      '#description'   => $this->t('E.g. <em>CEO, Dubai Retail Solutions</em>.'),
    ];
    $form['rating'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Star Rating (1–5)'),
      '#default_value' => $this->configuration['rating'],
      '#min'           => 1,
      '#max'           => 5,
      '#required'      => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['quote']       = $form_state->getValue('quote');
    $this->configuration['author_name'] = $form_state->getValue('author_name');
    $this->configuration['author_role'] = $form_state->getValue('author_role');
    $this->configuration['rating']      = (int) $form_state->getValue('rating');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#theme'       => 'block__fintech_testimonial_card_block',
      '#quote'       => $this->configuration['quote'],
      '#author_name' => $this->configuration['author_name'],
      '#author_role' => $this->configuration['author_role'],
      '#rating'      => $this->configuration['rating'],
      '#attached'    => ['library' => ['fintech_landing_page/fintech_page']],
      '#cache'       => ['max-age' => 3600],
    ];
  }

}
