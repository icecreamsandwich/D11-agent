<?php

declare(strict_types=1);

namespace Drupal\queue_demo\Exception;

/**
 * Thrown when the CRM is unreachable but the operation is safe to retry.
 *
 * Distinguishing TRANSIENT failures (network blip, 503, rate limit) from
 * PERMANENT ones (invalid payload, revoked credentials) is what lets the
 * queue worker decide between RequeueException (retry) and dropping /
 * dead-lettering the item. A real Salesforce client would throw this on
 * cURL timeouts and HTTP 5xx responses.
 */
final class CrmTemporarilyUnavailableException extends \RuntimeException {
}
