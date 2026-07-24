<?php

declare(strict_types=1);

namespace Drupal\spacex_launches\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Retrieves and caches launch data from The Space Devs API.
 */
final class LaunchesApiService {

  private const API_ENDPOINT = 'https://ll.thespacedevs.com/2.3.0/launches/';

  private const CACHE_ID = 'spacex_launches:latest';

  private const DATA_CACHE_TAG = 'spacex_launches:data';

  private const RENDER_CACHE_TAG = 'spacex_launches:rendered';

  /**
   * Constructs the launches service.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly CacheBackendInterface $cacheBackend,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Gets the latest launches, using cache when possible.
   */
  public function getLaunches(bool $refresh = FALSE, array $options = []): LaunchesApiResult {
    $resolvedOptions = $this->resolveOptions($options);
    $cacheId = $this->buildCacheId($resolvedOptions);

    if (!$refresh) {
      $cached = $this->cacheBackend->get($cacheId);
      if ($cached !== FALSE && is_array($cached->data)) {
        return new LaunchesApiResult(
          $cached->data['launches'] ?? [],
          $cached->data['fetched_at'] ?? NULL,
          NULL,
          (int) ($cached->data['total_count'] ?? 0),
          (int) ($cached->data['limit'] ?? 0),
          (int) ($cached->data['offset'] ?? 0),
        );
      }
    }

    return $this->requestLaunches($resolvedOptions, $cacheId);
  }

  /**
   * Invalidates the stored launch data cache.
   */
  public function invalidateCache(): void {
    $this->cacheTagsInvalidator->invalidateTags([self::DATA_CACHE_TAG, self::RENDER_CACHE_TAG]);
  }

  /**
   * Returns the configured cache max-age in seconds.
   */
  public function getCacheMaxAge(): int {
    $minutes = (int) $this->configFactory->get('spacex_launches.settings')->get('cache_ttl_minutes');
    return max(60, $minutes * 60);
  }

  /**
   * Returns the configured display limit.
   */
  public function getConfiguredResultsLimit(): int {
    return $this->normalizeLimit((int) $this->configFactory->get('spacex_launches.settings')->get('results_limit'));
  }

  /**
   * Returns the render cache tag used by the module page.
   */
  public function getRenderCacheTag(): string {
    return self::RENDER_CACHE_TAG;
  }

  /**
   * Requests fresh data from the remote API.
   *
   * @param array<string,mixed> $options
   *   Resolved request options.
   * @param string $cacheId
   *   The derived cache id for this request.
   */
  private function requestLaunches(array $options, string $cacheId): LaunchesApiResult {
    $query = $options['query'];

    try {
      $response = $this->httpClient->request('GET', $options['endpoint'], [
        'query' => $query,
        'headers' => [
          'Accept' => 'application/json',
        ],
        'timeout' => 10,
        'connect_timeout' => 5,
      ]);

      $statusCode = $response->getStatusCode();
      if ($statusCode !== 200) {
        $this->logger->error('Launch API returned unexpected status code @status.', [
          '@status' => $statusCode,
        ]);
        return $this->buildErrorResult('Launch data is temporarily unavailable.', $query);
      }

      $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      if (!is_array($payload) || !isset($payload['results']) || !is_array($payload['results'])) {
        $this->logger->error('Launch API returned an invalid payload.');
        return $this->buildErrorResult('Launch data is temporarily unavailable.', $query);
      }

      $fetchedAt = $this->time->getRequestTime();
      $launches = $this->normalizeLaunches($payload['results']);
      $totalCount = max(count($launches), (int) ($payload['count'] ?? count($launches)));

      $this->cacheBackend->set(
        $cacheId,
        [
          'launches' => $launches,
          'fetched_at' => $fetchedAt,
          'total_count' => $totalCount,
          'limit' => (int) $query['limit'],
          'offset' => (int) ($query['offset'] ?? 0),
        ],
        $fetchedAt + $this->getCacheMaxAge(),
        [self::DATA_CACHE_TAG]
      );
      $this->cacheTagsInvalidator->invalidateTags([self::RENDER_CACHE_TAG]);

      return new LaunchesApiResult(
        $launches,
        $fetchedAt,
        NULL,
        $totalCount,
        (int) $query['limit'],
        (int) ($query['offset'] ?? 0),
      );
    }
    catch (GuzzleException | \JsonException $exception) {
      $this->logger->error('Failed to fetch launch data: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return $this->buildErrorResult('Launch data is temporarily unavailable.', $query);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed to fetch launch data: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return $this->buildErrorResult('Launch data is temporarily unavailable.', $query);
    }
  }

  /**
   * Resolves runtime request options.
   */
  private function resolveOptions(array $options): array {
    $limit = $this->normalizeLimit((int) ($options['limit'] ?? $this->getConfiguredResultsLimit()));
    $offset = max(0, (int) ($options['offset'] ?? 0));
    $endpoint = isset($options['endpoint']) && is_string($options['endpoint']) && $options['endpoint'] !== ''
      ? $options['endpoint']
      : self::API_ENDPOINT;

    $query = [
      'format' => 'json',
      'mode' => 'normal',
      'ordering' => '-net',
      'limit' => $limit,
      'offset' => $offset,
    ];

    if (isset($options['query']) && is_array($options['query'])) {
      foreach ($options['query'] as $key => $value) {
        if (!is_scalar($value) && $value !== NULL) {
          continue;
        }
        $query[(string) $key] = $value;
      }
      $query['limit'] = $limit;
      $query['offset'] = $offset;
    }

    return [
      'endpoint' => $endpoint,
      'query' => $query,
    ];
  }

  /**
   * Builds a stable cache id for a request.
   *
   * @param array<string,mixed> $options
   *   Resolved request options.
   */
  private function buildCacheId(array $options): string {
    return self::CACHE_ID . ':' . hash('sha256', serialize($options));
  }

  /**
   * Normalizes a configured or requested limit.
   */
  private function normalizeLimit(int $limit): int {
    return min(100, max(1, $limit));
  }

  /**
   * Converts the API payload into a stable rendering structure.
   *
   * @param array<int,array<string,mixed>> $results
   *   Raw launch results from the remote API.
   *
   * @return array<int,array<string,mixed>>
   *   Normalized launches.
   */
  private function normalizeLaunches(array $results): array {
    $launches = [];

    foreach ($results as $result) {
      if (!is_array($result)) {
        continue;
      }

      $launches[] = [
        'name' => (string) ($result['name'] ?? ''),
        'rocket_name' => (string) ($result['rocket']['configuration']['full_name'] ?? $result['rocket']['configuration']['name'] ?? 'Unknown rocket'),
        'mission_name' => (string) ($result['mission']['name'] ?? 'Unknown mission'),
        'mission_type' => (string) ($result['mission']['type'] ?? ''),
        'launch_date' => (string) ($result['net'] ?? ''),
        'launch_provider' => (string) ($result['launch_service_provider']['name'] ?? ''),
        'status' => (string) ($result['status']['name'] ?? ''),
        'location' => (string) ($result['pad']['location']['name'] ?? ''),
        'pad_name' => (string) ($result['pad']['name'] ?? ''),
        'country' => (string) ($result['pad']['country']['name'] ?? ''),
        'mission_description' => (string) ($result['mission']['description'] ?? ''),
        'image_url' => (string) ($result['image']['image_url'] ?? ''),
        'image_alt' => (string) ($result['image']['name'] ?? $result['name'] ?? 'Launch image'),
      ];
    }

    usort($launches, static fn (array $a, array $b): int => strcmp((string) $b['launch_date'], (string) $a['launch_date']));

    return $launches;
  }

  /**
   * Creates a consistent error result.
   */
  private function buildErrorResult(string $message, array $query): LaunchesApiResult {
    return new LaunchesApiResult(
      [],
      NULL,
      $message,
      0,
      (int) ($query['limit'] ?? 0),
      (int) ($query['offset'] ?? 0),
    );
  }

}
