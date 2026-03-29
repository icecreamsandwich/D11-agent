<?php

declare(strict_types=1);

namespace Drupal\site_page_builder\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides a Contact Information block.
 */
#[Block(
  id: 'site_page_builder_contact_info',
  admin_label: new TranslatableMarkup('Contact Information'),
  category: new TranslatableMarkup('Site Page Builder'),
)]
final class ContactInfoBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'address'           => '',
      'email'             => '',
      'phone'             => '',
      'business_hours'    => '',
      'show_social_links' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['address'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Address'),
      '#default_value' => $this->configuration['address'],
      '#rows'          => 3,
      '#description'   => $this->t('Each line will be displayed on a new line.'),
    ];
    $form['email'] = [
      '#type'          => 'email',
      '#title'         => $this->t('Email Address'),
      '#default_value' => $this->configuration['email'],
    ];
    $form['phone'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Phone Number'),
      '#default_value' => $this->configuration['phone'],
    ];
    $form['business_hours'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Business Hours'),
      '#default_value' => $this->configuration['business_hours'],
      '#rows'          => 3,
      '#description'   => $this->t('E.g.<br>Monday - Friday<br>9:00 AM - 6:00 PM (EST)'),
    ];
    $form['show_social_links'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Show social media links'),
      '#default_value' => $this->configuration['show_social_links'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['address']           = $form_state->getValue('address');
    $this->configuration['email']             = $form_state->getValue('email');
    $this->configuration['phone']             = $form_state->getValue('phone');
    $this->configuration['business_hours']    = $form_state->getValue('business_hours');
    $this->configuration['show_social_links'] = (bool) $form_state->getValue('show_social_links');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#theme'             => 'block__site_page_builder_contact_info_block',
      '#address'           => $this->configuration['address'],
      '#email'             => $this->configuration['email'],
      '#phone'             => $this->configuration['phone'],
      '#business_hours'    => $this->configuration['business_hours'],
      '#show_social_links' => (bool) $this->configuration['show_social_links'],
      '#attached'          => ['library' => ['site_page_builder/page_builder']],
      '#cache'             => ['max-age' => 3600],
    ];
  }

}
