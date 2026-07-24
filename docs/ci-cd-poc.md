# CI/CD Pipeline POC — How It Works

This document explains the GitHub Actions pipeline in
[`.github/workflows/ci.yml`](../.github/workflows/ci.yml) and the scripts it
runs. It was set up as a proof of concept on the `feature-agent` branch.

## The big picture

```
   git push / pull request
          │
          ▼
   ┌─────────────┐
   │  validate    │  composer validate — is the dependency manifest sane?
   └──────┬──────┘
          │  (only if green)
   ┌──────┴───────────────────────────────┐
   ▼              ▼                       ▼
┌───────┐   ┌─────────┐            ┌─────────┐
│ phpcs │   │ phpstan │            │ phpunit │     ← run in PARALLEL
└───┬───┘   └────┬────┘            └────┬────┘
    └────────────┼──────────────────────┘
                 │  (only if ALL green)
                 ▼
          ┌────────────┐
          │   deploy    │  simulated — prints what scripts/deploy.sh does
          └────────────┘
```

Two ideas make this a *pipeline* rather than just "some scripts":

1. **Ordering with `needs:`** — a job only starts when the jobs it depends on
   succeeded. `deploy` therefore acts as a **quality gate**: broken code can
   never reach it.
2. **Triggers with `on:`** — every push to `main`/`feature-agent` and every PR
   against `main` runs the QA jobs; only `push` events reach the deploy job
   (PRs are verified but never deployed).

## What each job runs

| Job | Command | Catches |
|----|----|----|
| validate | `composer validate --no-check-all --no-check-publish` | Broken/out-of-sync `composer.json` + `composer.lock` |
| phpcs | `vendor/bin/phpcs` (config: [`phpcs.xml.dist`](../phpcs.xml.dist)) | Drupal coding-standard violations in `web/modules/custom` |
| phpstan | `vendor/bin/phpstan analyse` (config: [`phpstan.neon.dist`](../phpstan.neon.dist)) | Real bugs without running code: wrong argument counts, unknown methods, bad types |
| phpunit | `vendor/bin/phpunit -c web/core/phpunit.xml.dist <custom Unit dirs>` | Failing unit tests in custom modules |
| deploy | simulated — echoes the steps of [`scripts/deploy.sh`](../scripts/deploy.sh), builds a `--no-dev` artifact | Nothing; it's the reward for passing everything |

Support steps inside every QA job:

- **`shivammathur/setup-php`** — installs the PHP version + extensions.
- **`actions/cache`** — caches composer's download cache keyed on
  `composer.lock`, so repeat runs don't re-download every package.
- **Remove checked-in build artifacts** — this repo commits `vendor/` and
  contrib modules; CI deletes them and installs fresh from `composer.lock` so
  the lock file is proven to be a reproducible source of truth.

## What the POC caught (the red → green story)

The very first pipeline run failed — which is exactly the point of CI. In
order of discovery:

1. **validate**: `composer.lock` was missing 7 packages required by
   `composer.json` (config_split, pathauto, redirect, …). Fixed by committing
   the synced lock file.
2. **composer install in CI**: committed `web/modules/contrib/entity_clone`
   had no `.git` directory, so composer could not update this dev package.
   Fixed by building from the lock file alone (see clean step above).
3. **phpcs**: 132 coding-standard errors in custom modules. ~100 auto-fixed
   with `vendor/bin/phpcbf`, the rest by hand (docblocks, method naming).
4. **phpstan**: found a real production bug —
   `LaunchesApiService::buildErrorResult()` called with 1 argument instead
   of 2, which would throw `ArgumentCountError` exactly when the SpaceX API
   errors. Also found a fatal `declare(strict_types=1)` ordering bug in
   `d11_performance_optimizer.install`. Remaining 37 legacy findings were
   grandfathered into `phpstan-baseline.neon` (only *new* errors fail CI).
5. **phpunit**: `DatabaseQueryMonitorService::getRequestSummary()` returned
   int `0` instead of float `0.0` for `total_time` on an empty buffer
   (`array_sum([])` returns int). Fixed with an explicit float cast.

## The deploy stage — from simulation to real

The deploy job currently *prints* the steps of `scripts/deploy.sh` and uploads
a production build artifact (`composer install --no-dev`, tarred). To make it
real, replace the echo step with an SSH deployment:

```yaml
- name: Deploy to production
  uses: appleboy/ssh-action@v1
  with:
    host: ${{ secrets.DEPLOY_HOST }}
    username: ${{ secrets.DEPLOY_USER }}
    key: ${{ secrets.DEPLOY_SSH_KEY }}
    script: cd /var/www/site && ./scripts/deploy.sh production
```

Secrets live under **repo → Settings → Secrets and variables → Actions** and
are never printed in logs. `deploy.sh` then runs on the server: git pull,
`composer install --no-dev`, maintenance mode on, `drush updatedb`,
`drush config:import`, translation import, cache rebuild, maintenance mode off.

## Ideas to extend the POC

- **Kernel/Functional tests**: add a `services:` block with `mariadb` to the
  phpunit job and set `SIMPLETEST_DB=mysql://...`; then drop the
  Unit-only path filter.
- **Tighten the gates**: remove `ignore_warnings_on_exit` from
  `phpcs.xml.dist` once the 26 remaining warnings are fixed; raise phpstan
  `level` from 1 and shrink the baseline over time.
- **Restrict deploys**: change the deploy job's `if:` to
  `github.ref == 'refs/heads/main'` so only `main` deploys.
- **Branch protection**: require the QA checks to pass before a PR can merge
  (repo → Settings → Branches → protection rule for `main`).
