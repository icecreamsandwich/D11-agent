<?php

namespace Drupal\campaign_winner_selection\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Class ConfirmForm.
 *
 * Provides a confirmation before sending emails to campaign winners.
 */
class ConfirmForm extends ConfirmFormBase {

  /**
   * Returns the unique ID of the form.
   *
   * @return string
   *   The unique ID of the form.
   */
  public function getFormId() {
    return 'confirm_form';
  }

  /**
   * Returns the question to ask the user.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The question to ask the user.
   */
  public function getQuestion() {
    return $this->t('Do you want to send email to these users?');
  }

  /**
   * Returns the cancel URL.
   *
   * @return \Drupal\Core\Url
   *   The cancel URL.
   */
  public function getCancelUrl() {
    return Url::fromRoute('campaign_winner_selection.send_email_to_winners');
  }

  /**
   * Returns the description of the confirmation form.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The description of the confirmation form.
   */
  public function getDescription() {
    $winner_submissions = \Drupal::service('tempstore.private')->get('campaign_winner_selection')->get('winners');
    $winner_emails = $this->getWinnerEmails($winner_submissions);
    $emails = implode(PHP_EOL, $winner_emails);
    return $this->t('<b>Email will be sent to these users:</b> 
    <div class="email-box">
    <pre>@emails</pre></div><br/>Please confirm.', ['@emails' => $emails]);
  }

  /**
   * Builds the form for confirming the email sending action.
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
    // Attach the library to the form.
    $form['#attached']['library'][] = 'campaign_winner_selection/custom_styles';

    // Add any other form elements here as needed.
    return parent::buildForm($form, $form_state);
  }

  /**
   * Returns the text for the confirmation button.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The confirmation button text.
   */
  public function getConfirmText() {
    return $this->t('Submit');
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
    // Retrieve data from tempstore.
    $winner_submissions = \Drupal::service('tempstore.private')->get('campaign_winner_selection')->get('winners');

    // Send emails to voucher winners.
    foreach ($winner_submissions as $submission) {
      $first_name = $submission->getElementData('first_name');
      $last_name = $submission->getElementData('last_name');
      $winner_name  = $first_name." ".$last_name;
      $email = $submission->getElementData('email');
      $coupon_code = $submission->getElementData('voucher_code');
      $voucher_url = $submission->getElementData('voucher_url');
      $event_type = $submission->getElementData('event_type');
      if ($event_type == 'voucher_code_winners') {
        _campaign_winner_selection_send_email($winner_name, $email, $coupon_code, 'voucher', $voucher_url);
      }
      else {
        _campaign_winner_selection_send_email($winner_name, $email, '', 'winner', $voucher_url);
      }
      //Update the email send status
      if(_campaign_winner_selection_update_winners_email($submission)){
        \Drupal::logger('campaign_winner_selection')->notice('Submission email status updated succesfully');
      }
    }
    
    // Clear the tempstore.
    \Drupal::service('tempstore.private')->get('campaign_winner_selection')->delete('winners');

    // Show a confirmation message.
    \Drupal::messenger()->addMessage($this->t('Email sent to users successfully.'));

    $form_state->setRedirect('campaign_winner_selection.select_winners_form');
  }

  /**
   * Get the winner emails from submissions.
   *
   * @param array $winner_submissions
   *   An array of winner submissions.
   *
   * @return array
   *   An array of email addresses.
   */
  public function getWinnerEmails($winner_submissions) {
    $emails = [];
    foreach ($winner_submissions as $submission) {
      $emails[] = $submission->getElementData('email');
    }
    return $emails;
  }

}
