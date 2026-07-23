<?php

declare(strict_types=1);

namespace Drupal\queue_demo\Service;

/**
 * Contract for CRM lead storage.
 *
 * The queue worker depends on this INTERFACE, not on MockCRMService.
 * Swapping the mock for a real Salesforce/HubSpot client is a one-line
 * change in queue_demo.services.yml — no consumer code changes.
 */
interface CRMServiceInterface {

  /**
   * Persists a contact lead in the CRM.
   *
   * @param string $name
   *   Lead full name.
   * @param string $email
   *   Lead email address.
   * @param string $message
   *   The message they submitted.
   *
   * @return string
   *   The CRM-side lead identifier.
   *
   * @throws \Drupal\queue_demo\Exception\CrmTemporarilyUnavailableException
   *   When the CRM cannot be reached right now (transient — safe to retry).
   * @throws \RuntimeException
   *   On permanent failures (bad data, auth misconfiguration).
   */
  public function saveLead(string $name, string $email, string $message): string;

}
