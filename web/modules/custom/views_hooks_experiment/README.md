# Views Hooks Experiment

Custom Drupal module that auto-creates a view on install and implements every
major Views hook so you can watch each one fire.

## Install

```bash
drush en views_hooks_experiment -y
drush cr
```

Installation automatically:

1. Creates the `views_hooks_experiment_log` table (`hook_schema()`) and seeds
   7 sample rows, including one with severity `hidden`.
2. Imports the **Views Hooks Experiment** view from `config/install`, with a
   page display at `/views-hooks-experiment`.

## Try it

Visit `/views-hooks-experiment`. Numbered status messages show each runtime
hook firing, in order.

| # | Hook | How to verify it worked |
|---|------|-------------------------|
| — | `hook_views_data()` | The view exists at all — its base table is the custom table this hook exposes. Watchdog entry after `drush cr`. |
| — | `hook_views_data_alter()` | In the Views UI, add a field to any node view: the node **Title** field is relabelled "Title (altered by Views Hooks Experiment)". |
| 1 | `hook_views_pre_view()` | Pager shows 5 items per page even though the saved view config says 10. |
| 2 | `hook_views_query_alter()` | The seeded "SECRET row" (severity `hidden`) never appears in results. |
| 3 | `hook_views_pre_execute()` | The final SQL — including the injected `severity != 'hidden'` condition — is printed on screen. |
| 4 | `hook_views_post_execute()` | Raw row count is printed; rows are tagged with a marker property. |
| 5 | `hook_views_pre_render()` | Page title is rewritten and every Message value gets a suffix confirming the post_execute marker survived. |
| 6 | `hook_form_views_exposed_form_alter()` | The "Message contains" filter has a placeholder + description, and the submit button is renamed. |

The exposed **Message contains** and **Severity** filters are fully
functional — try `severity = hidden` to confirm the query alter still wins.

## Uninstall

```bash
drush pmu views_hooks_experiment -y
```

Drops the custom table and deletes the view.
