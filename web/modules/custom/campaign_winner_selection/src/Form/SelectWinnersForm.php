<?php

namespace Drupal\campaign_winner_selection\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Entity\Webform;

/**
 * Class SelectWinnersForm.
 *
 * Provides a form for selecting campaign winners.
 */
class SelectWinnersForm extends FormBase {

  /**
   * Returns the unique ID of the form.
   *
   * @return string
   *   The unique ID of the form.
   */
  public function getFormId() {
    return 'select_winners_form';
  }

  /**
   * Builds the form for selecting campaign winners.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    // Add fields for selecting campaign and coupon codes.
    $form['campaign'] = [
      '#type' => 'select',
      '#title' => $this->t('Campaign'),
      '#description' => $this->t('Select the Campaign'),
      '#options' => $this->getCampaignWebformOptions(),
    ];

    // Event Type.
    $form['event_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Event Type'),
      '#description' => $this->t('Please Select an Event Type'),
      '#options' => [
        'voucher_code_winners' => $this->t('Voucher Code Winners'),
        'non_voucher_code_winners' => $this->t('Non Voucher Code Winners'),
      ],
      '#required' => TRUE,
    ];

    // Number of Users.
    $form['no_of_users'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of Users'),
      '#required' => TRUE,
    ];

    // Country List.
    $form['country'] = [
      '#type' => 'select',
      '#title' => $this->t('Country'),
      '#options' => $this->getCountryList(),
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Select Winners'),
    ];

    return $form;
  }

  /**
   * Handles form submission.
   *
   * @param array &$form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $campaign = $form_state->getValue('campaign');
    $event_type = $form_state->getValue('event_type');
    $user_count = $form_state->getValue('no_of_users');
    $country = $form_state->getValue('country');

    $selected_users = _campaign_winner_selection_get_winners($campaign, $event_type, $country, $user_count);

    if (empty($selected_users)) {
      \Drupal::messenger()->addError($this->t('Sorry. No Users exist for the campaign for the country.'));
      return FALSE;
    }

    \Drupal::messenger()->addStatus($this->t('Thank you for choosing the contest. Winners have been selected'));
  }

  /**
   * Get webform options with "Campaign" in their title.
   *
   * @return array
   *   An array of webform options.
   */
  public function getCampaignWebformOptions() {
    $options = [];
    $webforms = Webform::loadMultiple();

    foreach ($webforms as $webform) {
      $title = $webform->label();
      if (strpos($title, 'Campaign') !== FALSE) {
        $options[$webform->id()] = $title;
      }
    }
    return $options;
  }

  /**
   * Get the list of countries.
   *
   * @return array
   *   An array of countries.
   */
  protected function getCountryList() {
    $country_list = _campaign_winner_selection_get_country_list();
    return $country_list;
  }

}
