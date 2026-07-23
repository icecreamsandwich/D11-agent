<?php

declare(strict_types=1);

namespace Drupal\spacex_launches\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\spacex_launches\Service\LaunchesApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Page controller for launch data.
 */
final class LaunchesController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly LaunchesApiService $launchesApi,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly MessengerInterface $messengerService,
    private readonly PagerManagerInterface $pagerManager,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('spacex_launches.api'),
      $container->get('date.formatter'),
      $container->get('messenger'),
      $container->get('pager.manager'),
      $container->get('request_stack'),
    );
  }

  /**
   * Builds the launches page.
   */
  public function page(): array {
    $request = $this->requestStack->getCurrentRequest();
    $currentPage = max(0, (int) $request->query->get('page', 0));
    $limit = $this->launchesApi->getConfiguredResultsLimit();
    $offset = $currentPage * $limit;
    $result = $this->launchesApi->getLaunches(FALSE, [
      'limit' => $limit,
      'offset' => $offset,
    ]);

    $this->pagerManager->createPager($result->totalCount, $limit);
    $destination = $request->getRequestUri();

    $build = [
      '#theme' => 'spacex_launches_page',
      '#launches' => $result->launches,
      '#last_fetched' => $result->fetchedAt ? $this->dateFormatter->format($result->fetchedAt, 'custom', 'Y-m-d H:i:s T') : NULL,
      '#error_message' => $result->errorMessage,
      '#refresh_url' => Url::fromRoute('spacex_launches.refresh', [], [
        'query' => [
          'page' => $currentPage,
          'destination' => $destination,
        ],
      ])->toString(),
      '#pager' => [
        '#type' => 'pager',
      ],
      '#cache' => [
        'contexts' => ['user.permissions', 'url.query_args:page'],
        'tags' => ['config:spacex_launches.settings', $this->launchesApi->getRenderCacheTag()],
        'max-age' => $this->launchesApi->getCacheMaxAge(),
      ],
    ];

    return $build;
  }

  /**
   * Forces a refresh and redirects back to the page.
   */
  public function refresh(): RedirectResponse {
    $request = $this->requestStack->getCurrentRequest();
    $page = max(0, (int) $request->query->get('page', 0));
    $limit = $this->launchesApi->getConfiguredResultsLimit();

    $this->launchesApi->invalidateCache();
    $result = $this->launchesApi->getLaunches(TRUE, [
      'limit' => $limit,
      'offset' => $page * $limit,
    ]);

    if ($result->errorMessage !== NULL) {
      $this->messengerService->addError($this->t('Unable to refresh launch data right now.'));
    }
    else {
      $this->messengerService->addStatus($this->t('Launch data refreshed.'));
    }

    $destination = $request->query->getString('destination');
    if ($destination !== '') {
      return new RedirectResponse($destination);
    }

    return $this->redirect('spacex_launches.page');
  }

}
