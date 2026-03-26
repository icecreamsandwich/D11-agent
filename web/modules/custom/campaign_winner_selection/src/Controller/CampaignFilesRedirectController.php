<?php

namespace Drupal\campaign_winner_selection\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Class CampaignFilesRedirectController.
 *
 * Provides redirection functionality for campaign-related webforms.
 */
class CampaignFilesRedirectController extends ControllerBase {

  /**
   * Redirects to the webform.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   The redirect response.
   */
  public function redirectToWebform() {
    return new RedirectResponse('/form/campaign-upload-coupon-codes');
  }

  /**
   * Redirects to the webform import submissions.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   The redirect response.
   */
  public function redirectToWebformUploadUsers() {
    return new RedirectResponse('/form/campaign-upload-users');
  }

}
