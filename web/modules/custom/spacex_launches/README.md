# SpaceX Launches

`spacex_launches` is a custom Drupal 11 module that fetches launch data from The
Space Devs Launch Library API, caches the response, and renders launch details
through Twig with permission-based access.

## Drupal and PHP versions

- Drupal core: 11.x
- PHP: 8.2+ assumed

## Features

- Public route at `/spacex-launches`
- Custom permission for page access
- Config form at `/admin/config/services/spacex-launches`
- Guzzle-based API integration through a dedicated service
- Configurable cache TTL, launch display limit, and cron refresh interval
- Twig-based rendering of launch cards
- Manual cache invalidation and immediate refetch via a CSRF-protected refresh link
- Pager support on the launches page using the remote list count
- Reusable block plugin with per-block endpoint/query overrides
- Queue worker plus cron integration for background refreshes
- Error logging through `logger.channel.spacex_lauches`
- Unit and kernel test coverage

## Installation

1. Place the module in `web/modules/custom/spacex_launches`.
2. Enable it with Drush:

```bash
drush en spacex_launches -y
drush cr
```

3. Or enable it through the Drupal admin UI at `Extend`.

## Configuration

1. Visit `/admin/config/services/spacex-launches`.
2. Set:
   - Cache TTL in minutes
   - Number of launches to display
   - Cron refresh interval in minutes
3. Save configuration.
4. Grant one or both permissions as needed:
   - `access spacex launches page`
   - `administer spacex launches settings`

## Testing

1. Enable the module.
2. Grant `access spacex launches page` to a test role.
3. Visit `/spacex-launches` and confirm launch data renders.
4. Change cache TTL and result limit in the config form and verify the output updates.
5. Use the `Refresh now` link to invalidate cached data and force a fresh API request.
6. Place the `SpaceX launches` block and optionally override its endpoint or query string.
7. Run cron and confirm a `spacex_launches_refresh` queue item is processed.
8. Review Recent log messages for any API/network failures.

## Tests

```bash
./vendor/bin/phpunit web/modules/custom/spacex_launches/tests/src/Unit
./vendor/bin/phpunit web/modules/custom/spacex_launches/tests/src/Kernel
```

## Assumptions

- The project uses Drupal's standard custom module path: `web/modules/custom`.
- The Launch Library endpoint `https://ll.thespacedevs.com/2.3.0/launches/`
  is reachable from the Drupal environment.
- No API key is required.
- Launch dates are rendered directly from the API timestamp string for simplicity.
