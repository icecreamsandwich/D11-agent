<?php

declare(strict_types=1);

namespace Drupal\queue_demo\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Contact form that defers all heavy processing to the Queue API.
 *
 * Responsibility of this class ends at: validate input, persist it as a
 * queue item, thank the user. Email + CRM work happens later in
 * ContactSubmissionQueueWorker, so the HTTP response is instant and a
 * mail-server outage can never break form submission.
 */
final class ContactForm extends FormBase {

  /**
   * Queue name — must match the QueueWorker plugin ID.
   */
  public const QUEUE_NAME = 'queue_demo_contact_queue';

  /**
   * Constructs the form with injected dependencies (no \Drupal:: calls).
   */
  public function __construct(
    private readonly QueueFactory $queueFactory,
    private readonly LoggerInterface $logger,
    private readonly TimeInterface $time,
    MessengerInterface $messenger,
  ) {
    // FormBase ships a MessengerTrait; we satisfy it via injection.
    $this->setMessenger($messenger);
  }

  /**
   * {@inheritdoc}
   *
   * Factory method: the container hands us services so the constructor
   * stays testable — in a unit test you pass mocks directly.
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('queue'),
      $container->get('logger.channel.queue_demo'),
      $container->get('datetime.time'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'queue_demo_contact_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#required' => TRUE,
      '#maxlength' => 100,
    ];
    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#required' => TRUE,
    ];
    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#required' => TRUE,
      '#rows' => 5,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * The 'email' element type already validates format; we add business rules.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (mb_strlen(trim((string) $form_state->getValue('name'))) < 2) {
      $form_state->setErrorByName('name', $this->t('Please enter your full name (at least 2 characters).'));
    }
    if (mb_strlen(trim((string) $form_state->getValue('message'))) < 10) {
      $form_state->setErrorByName('message', $this->t('Please provide a bit more detail (at least 10 characters).'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * NOTE what this method does NOT do: no email, no CRM call, no external
   * I/O. It only enqueues — O(1 INSERT) — and returns. That is the entire
   * point of the Queue API pattern.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $item = [
      'name' => trim((string) $form_state->getValue('name')),
      'email' => trim((string) $form_state->getValue('email')),
      'message' => trim((string) $form_state->getValue('message')),
      'submitted' => $this->time->getRequestTime(),
    ];

    // QueueFactory returns (and lazily creates) the named queue. With core's
    // default backend this is a row-set in the {queue} table.
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    $item_id = $queue->createItem($item);

    $this->logger->info('Queue item created (item @id) for @email in queue %queue.', [
      '@id' => $item_id,
      '@email' => $item['email'],
      '%queue' => self::QUEUE_NAME,
    ]);

    $this->messenger()->addStatus($this->t('Thank you. Your request is being processed.'));
  }

}
