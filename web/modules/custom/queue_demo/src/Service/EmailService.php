<?php

declare(strict_types=1);

namespace Drupal\queue_demo\Service;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends the contact confirmation email via Drupal's Mail Manager.
 *
 * The Mail Manager routes through the configured mail plugin (PHP mail,
 * Symfony Mailer, SMTP module, ...) and fires our hook_mail() with the
 * 'contact_request' key to build subject and body. Swapping the transport
 * never touches this class.
 */
final class EmailService {

  /**
   * Mail key handled in queue_demo_mail().
   */
  private const MAIL_KEY = 'contact_request';

  public function __construct(
    private readonly MailManagerInterface $mailManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Sends the "Contact Request Received" email to the submitter.
   *
   * @throws \RuntimeException
   *   If the mail system reports a send failure (caller decides whether
   *   to retry via the queue).
   */
  public function sendContactConfirmation(string $name, string $email, string $message): void {
    $result = $this->mailManager->mail(
      'queue_demo',
      self::MAIL_KEY,
      $email,
      $this->languageManager->getDefaultLanguage()->getId(),
      [
        'name' => $name,
        'email' => $email,
        'message' => $message,
      ],
    );

    if (empty($result['result'])) {
      throw new \RuntimeException(sprintf('Mail system failed to send confirmation to %s.', $email));
    }

    $this->logger->info('Email sent to @email (subject: Contact Request Received).', [
      '@email' => $email,
    ]);
  }

}
