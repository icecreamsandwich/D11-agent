# D11 Performance Optimizer

A production-ready Drupal 11 module that automatically improves site performance,
rendering efficiency, asset delivery, SEO, and enforces coding standards.

---

## Features

| Feature | Description |
|---|---|
| Performance Analyzer | Tracks page load time, Twig render time, memory, query count, cache ratios |
| Database Query Monitor | Detects slow queries, duplicates, and N+1 patterns |
| Cache Analyzer | Audits page cache, dynamic page cache, and render cache settings |
| SEO Optimization | Auto-injects canonical URL, robots meta, og:type; detects missing alt/title |
| Coding Standards Validator | Static analysis for PSR-12 violations, deprecated Drupal APIs, missing DI |
| Recommendation Engine | Prioritized actionable recommendations based on live metrics |
| Performance Logger | Persists slow requests, high memory, large responses to DB |
| Admin Dashboard | Full `/admin/reports/performance-optimizer` dashboard with 6 sections |
| Performance Summary Block | Compact block showing last-hour metrics for admins |
| Settings Form | All thresholds, features, and retention fully configurable |

---

## Installation

### Via Drush (recommended)

```bash
# Place the module in your custom modules directory
cp -r d11_performance_optimizer web/modules/custom/

# Enable the module
ddev exec drush en d11_performance_optimizer -y

# Clear caches
ddev exec drush cr
```

### Via Composer (if hosted in a package repository)

```bash
composer require drupal/d11_performance_optimizer
drush en d11_performance_optimizer -y
```

---

## Configuration

Visit `/admin/config/system/performance-optimizer` or navigate to:

**Admin → Configuration → System → Performance Optimizer**

### Key settings

| Setting | Default | Description |
|---|---|---|
| `slow_request_threshold` | 2000 ms | Flags slow pages |
| `slow_query_threshold` | 100 ms | Flags slow DB queries |
| `high_memory_threshold` | 64 MB | Flags high memory usage |
| `sampling_rate` | 10 | Record 1 in N requests (reduce DB writes in production) |
| `enable_coding_standards` | false | Enable for development environments only |
| `log_retention_days` | 30 | Auto-purge old logs on cron |

---

## Dashboard

Visit `/admin/reports/performance-optimizer`

**Sections:**

- **Overview** — 24-hour aggregate metrics and recommendations
- **Performance** — Per-request metrics table (last 50 samples)
- **Database** — Slow queries ranked by execution time and occurrence
- **Assets** — CSS/JS aggregation and optimization status
- **SEO / Logs** — Performance and SEO event log
- **Coding Standards** — Static analysis results for `/modules/custom`

---

## Permissions

| Permission | Purpose |
|---|---|
| `view performance optimizer dashboard` | Read access to all dashboard pages |
| `administer performance optimizer` | Settings, clear metrics |

---

## Running Tests

```bash
# Unit tests (fast, no Drupal bootstrap)
ddev exec ./vendor/bin/phpunit web/modules/custom/d11_performance_optimizer/tests/src/Unit

# Kernel tests (partial Drupal bootstrap, real DB schema)
ddev exec ./vendor/bin/phpunit web/modules/custom/d11_performance_optimizer/tests/src/Kernel

# Functional tests (full browser simulation)
ddev exec ./vendor/bin/phpunit web/modules/custom/d11_performance_optimizer/tests/src/Functional
```

---

## Architecture

```
d11_performance_optimizer/
├── d11_performance_optimizer.info.yml          # Module metadata
├── d11_performance_optimizer.module            # Hooks (help, page_attachments_alter, cron, toolbar)
├── d11_performance_optimizer.install           # DB schema (3 tables), uninstall cleanup
├── d11_performance_optimizer.services.yml      # Service container definitions
├── d11_performance_optimizer.routing.yml       # Admin routes (8 routes)
├── d11_performance_optimizer.permissions.yml   # 2 permissions
├── d11_performance_optimizer.links.menu.yml    # Admin menu links
├── d11_performance_optimizer.libraries.yml     # CSS library
├── config/install/
│   └── d11_performance_optimizer.settings.yml  # Default configuration
├── css/
│   └── dashboard.css
└── src/
    ├── Controller/
    │   └── AdminDashboardController.php        # 6 dashboard pages + clear action
    ├── Form/
    │   └── SettingsForm.php                    # ConfigFormBase settings page
    ├── Service/
    │   ├── PerformanceAnalyzerService.php      # Timer, metrics capture, aggregation
    │   ├── DatabaseQueryMonitorService.php     # Slow query detection, N+1 patterns
    │   ├── CacheAnalyzerService.php            # Cache module/config audit
    │   ├── SEOOptimizationService.php          # Auto SEO injection + HTML analysis
    │   ├── CodingStandardsValidatorService.php # Static PHP analysis
    │   ├── RecommendationEngineService.php     # Prioritized recommendations
    │   └── PerformanceLoggerService.php        # Structured DB logging
    ├── EventSubscriber/
    │   ├── RequestPerformanceSubscriber.php    # KernelEvents::REQUEST / RESPONSE
    │   └── RenderOptimizationSubscriber.php    # Response analysis, cache-miss logging
    └── Plugin/Block/
        └── PerformanceSummaryBlock.php         # Admin-only summary block
```

---

## Database Tables

| Table | Purpose |
|---|---|
| `d11_performance_metrics` | Per-request performance samples |
| `d11_performance_logs` | Structured event log (slow request, high memory, etc.) |
| `d11_slow_queries` | Deduplicated slow query records with occurrence count |

All tables are purged automatically on cron based on configured retention periods.

---

## Production Safety

- **Sampling rate** defaults to `10` — only 1 in 10 requests writes metrics to DB
- All features are individually toggle-able; disable any that add overhead
- Coding Standards validation is **disabled by default** (enable only in dev/CI)
- Request/response subscribers skip `/admin` and `/_` paths to reduce noise
- All DB writes are wrapped in try/catch — the module never breaks page rendering

---

## Coding Standards

This module itself follows:
- PSR-12
- Drupal coding standards
- Strict types on all PHP files
- Dependency injection throughout (no `\Drupal::` in services or controllers)
- `readonly` constructor promotion
- `final` classes
- PHP 8.2+ attribute-based block plugins
