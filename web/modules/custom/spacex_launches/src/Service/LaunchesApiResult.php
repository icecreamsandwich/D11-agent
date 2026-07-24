<?php

declare(strict_types=1);

namespace Drupal\spacex_launches\Service;

/**
 * Value object representing a launch API response state.
 */
final class LaunchesApiResult {

  /**
   * Constructs a result object.
   *
   * @param array<int,array<string,mixed>> $launches
   *   Normalized launches ready for rendering.
   * @param int|null $fetchedAt
   *   Unix timestamp for the last successful fetch.
   * @param string|null $errorMessage
   *   Human-friendly error message for display.
   * @param int $totalCount
   *   The total number of remote results for the current query.
   * @param int $limit
   *   The request limit used for this result set.
   * @param int $offset
   *   The request offset used for this result set.
   */
  public function __construct(
    public readonly array $launches,
    public readonly ?int $fetchedAt = NULL,
    public readonly ?string $errorMessage = NULL,
    public readonly int $totalCount = 0,
    public readonly int $limit = 0,
    public readonly int $offset = 0,
  ) {}

}
