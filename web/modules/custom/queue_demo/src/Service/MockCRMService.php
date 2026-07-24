<?php

declare(strict_types=1);

namespace Drupal\queue_demo\Service;

use Drupal\queue_demo\Exception\CrmTemporarilyUnavailableException;
use Psr\Log\LoggerInterface;

/**
 * Fake CRM backend: logs leads instead of calling a real API.
 *
 * Implements CRMServiceInterface so it is a drop-in stand-in for a real
 * Salesforce client. Everything a real client would do over HTTP, this
 * class does with a log entry.
 */
final class MockCRMService implements CRMServiceInterface {

  /**
   * Magic marker: include this in the message to simulate a CRM outage.
   *
   * Lets you test the retry/requeue path deterministically from the UI.
   */
  private const SIMULATE_FAILURE_MARKER = 'FAIL_CRM';

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function saveLead(string $name, string $email, string $message): string {
    // Deterministic failure hook for testing the requeue path.
    if (str_contains($message, self::SIMULATE_FAILURE_MARKER)) {
      throw new CrmTemporarilyUnavailableException('Mock CRM outage triggered by FAIL_CRM marker.');
    }

    $lead_id = 'LEAD-' . strtoupper(bin2hex(random_bytes(4)));

    $this->logger->info('Saving Lead to CRM — ID: @lead_id | Name: @name | Email: @email | Message: @message', [
      '@lead_id' => $lead_id,
      '@name' => $name,
      '@email' => $email,
      '@message' => $message,
    ]);

    return $lead_id;
  }

}
