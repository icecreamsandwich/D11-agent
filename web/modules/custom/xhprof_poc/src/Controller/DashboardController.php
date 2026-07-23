<?php

declare(strict_types=1);

namespace Drupal\xhprof_poc\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\xhprof_poc\Profiler\ProfilerService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Dashboard UI: run list, per-section waterfall, XHProf function table.
 */
final class DashboardController extends ControllerBase {

  public function __construct(
    private readonly ProfilerService $profiler,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xhprof_poc.profiler'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Lists stored profiler runs, newest first.
   */
  public function runs(): array {
    $rows = [];
    foreach ($this->profiler->getRuns() as $run) {
      $rows[] = [
        Link::createFromRoute($run['id'], 'xhprof_poc.run_detail', ['run_id' => $run['id']]),
        $run['uri'],
        $this->dateFormatter->format($run['timestamp'], 'short'),
        $run['total_ms'] . ' ms',
        count($run['sections']),
        $run['xhprof']['enabled']
          ? $this->t('@count functions', ['@count' => $run['xhprof']['total_functions']])
          : $this->t('off'),
      ];
    }

    return [
      '#cache' => ['max-age' => 0],
      '#attached' => ['library' => ['xhprof_poc/dashboard']],
      'status' => [
        '#markup' => '<p class="xp-status ' . ($this->profiler->isXhprofAvailable() ? 'xp-ok' : 'xp-warn') . '">'
        . ($this->profiler->isXhprofAvailable()
          ? $this->t('XHProf extension is enabled.')
          : $this->t('XHProf extension is NOT loaded. Run "ddev xhprof on", then "ddev restart" if needed. Section timers still work without it.'))
        . '</p>',
      ],
      'actions' => [
        '#type' => 'link',
        '#title' => $this->t('Trigger a new profiled run (demo page)'),
        '#url' => Url::fromRoute('xhprof_poc.demo'),
        '#attributes' => ['class' => ['button', 'button--primary']],
        '#suffix' => '<p class="xp-hint">' . $this->t('Tip: append ?xhprof_poc=1 to any uncached page on this site to profile it too.') . '</p>',
      ],
      'runs' => [
        '#type' => 'table',
        '#caption' => $this->t('Stored runs (latest @max kept)', ['@max' => ProfilerService::MAX_RUNS]),
        '#header' => [
          $this->t('Run'),
          $this->t('URI'),
          $this->t('When'),
          $this->t('Total time'),
          $this->t('Sections'),
          $this->t('XHProf'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No runs recorded yet. Visit the demo page to create one.'),
      ],
    ];
  }

  /**
   * Detail view for one run: summary cards, waterfall, tables.
   */
  public function detail(string $run_id): array {
    $run = $this->profiler->getRun($run_id);
    if (!$run) {
      throw new NotFoundHttpException();
    }

    $total = max((float) $run['total_ms'], 0.001);
    $sections = [];
    foreach ($run['sections'] as $i => $s) {
      $sections[] = [
        'label' => $s['label'],
        'offset_ms' => $s['offset_ms'],
        'duration_ms' => $s['duration_ms'],
        'pct' => round($s['duration_ms'] / $total * 100, 1),
        'offset_pct' => round($s['offset_ms'] / $total * 100, 2),
        'width_pct' => max(round($s['duration_ms'] / $total * 100, 2), 0.3),
        'color' => $i % 6,
        'memory_kb' => round($s['memory_delta'] / 1024, 1),
      ];
    }

    $build = [
      '#cache' => ['max-age' => 0],
      '#attached' => ['library' => ['xhprof_poc/dashboard']],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('← All runs'),
        '#url' => Url::fromRoute('xhprof_poc.dashboard'),
      ],
      'cards' => [
        '#type' => 'inline_template',
        '#template' => '<div class="xp-cards">
          <div class="xp-card"><span class="xp-card-value">{{ total }} ms</span><span class="xp-card-label">{% trans %}Total (routing → response){% endtrans %}</span></div>
          <div class="xp-card"><span class="xp-card-value">{{ bootstrap }} ms</span><span class="xp-card-label">{% trans %}Bootstrap before profiler{% endtrans %}</span></div>
          <div class="xp-card"><span class="xp-card-value">{{ memory }} MB</span><span class="xp-card-label">{% trans %}Peak memory{% endtrans %}</span></div>
          <div class="xp-card"><span class="xp-card-value">{{ functions }}</span><span class="xp-card-label">{% trans %}XHProf functions{% endtrans %}</span></div>
        </div>',
        '#context' => [
          'total' => $run['total_ms'],
          'bootstrap' => $run['bootstrap_ms'],
          'memory' => round($run['peak_memory'] / 1048576, 1),
          'functions' => $run['xhprof']['enabled'] ? $run['xhprof']['total_functions'] : $this->t('off'),
        ],
      ],
      'waterfall_title' => ['#markup' => '<h2>' . $this->t('Section waterfall') . '</h2>'],
      'waterfall' => [
        '#type' => 'inline_template',
        '#template' => '<div class="xp-waterfall">
          {% for s in sections %}
          <div class="xp-row">
            <div class="xp-row-label" title="{{ s.label }}">{{ s.label }}</div>
            <div class="xp-track">
              <div class="xp-bar xp-c{{ s.color }}" style="left: {{ s.offset_pct }}%; width: {{ s.width_pct }}%"></div>
              <span class="xp-ms">{{ s.duration_ms }} ms ({{ s.pct }}%)</span>
            </div>
          </div>
          {% endfor %}
          {% if not sections %}<p>{% trans %}No sections were recorded for this run.{% endtrans %}</p>{% endif %}
        </div>',
        '#context' => ['sections' => $sections],
      ],
      'sections_table' => [
        '#type' => 'table',
        '#caption' => $this->t('Sections'),
        '#attributes' => ['class' => ['xp-sortable']],
        '#header' => [
          $this->t('Section'),
          $this->t('Start offset (ms)'),
          $this->t('Duration (ms)'),
          $this->t('% of total'),
          $this->t('Memory delta (KB)'),
        ],
        '#rows' => array_map(fn(array $s): array => [
          $s['label'],
          $s['offset_ms'],
          $s['duration_ms'],
          $s['pct'],
          $s['memory_kb'],
        ], $sections),
        '#empty' => $this->t('No sections recorded.'),
      ],
    ];

    if ($run['xhprof']['enabled']) {
      $fn_rows = array_map(fn(array $f): array => [
        ['data' => $f['fn'], 'class' => ['xp-fn']],
        $f['calls'],
        $f['wt_ms'],
        $f['excl_wt_ms'],
        $f['cpu_ms'],
        $f['mu_kb'],
      ], $run['xhprof']['top']);

      $build['functions_title'] = [
        '#markup' => '<h2>' . $this->t('XHProf — top @count functions by inclusive wall time (click headers to sort)', ['@count' => count($fn_rows)]) . '</h2>',
      ];
      $build['functions'] = [
        '#type' => 'table',
        '#attributes' => ['class' => ['xp-sortable']],
        '#header' => [
          $this->t('Function'),
          $this->t('Calls'),
          $this->t('Incl. wall (ms)'),
          $this->t('Excl. wall (ms)'),
          $this->t('CPU (ms)'),
          $this->t('Memory (KB)'),
        ],
        '#rows' => $fn_rows,
      ];
    }
    else {
      $build['functions'] = [
        '#markup' => '<p class="xp-status xp-warn">' . $this->t('XHProf data was not captured for this run (extension not loaded). Run "ddev xhprof on" and profile again.') . '</p>',
      ];
    }

    return $build;
  }

}
