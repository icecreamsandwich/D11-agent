<?php

declare(strict_types=1);

namespace Drupal\d11_performance_optimizer\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\d11_performance_optimizer\Service\PerformanceAnalyzerService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a compact performance summary block for admins.
 */
#[Block(
  id: 'd11_performance_summary',
  admin_label: new TranslatableMarkup('Performance Summary'),
  category: new TranslatableMarkup('Performance'),
)]
final class PerformanceSummaryBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly PerformanceAnalyzerService $performanceAnalyzer,
    private readonly AccountInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('d11_performance_optimizer.performance_analyzer'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    if (!$this->currentUser->hasPermission('view performance optimizer dashboard')) {
      return [];
    }

    $metrics = $this->performanceAnalyzer->getAggregatedMetrics(1);

    if (empty($metrics)) {
      return [
        '#markup' => '<p>' . $this->t('No performance data collected in the last hour.') . '</p>',
        '#cache' => ['max-age' => 300],
      ];
    }

    return [
      '#theme' => 'item_list',
      '#title' => $this->t('Last Hour Performance'),
      '#items' => [
        $this->t('Avg load: @v ms', ['@v' => $metrics['avg_page_load_time']]),
        $this->t('Avg queries: @v', ['@v' => $metrics['avg_db_query_count']]),
        $this->t('Cache hits: @v%', ['@v' => round(($metrics['cache_hit_ratio'] ?? 0) * 100, 0)]),
        $this->t('Slow requests: @v', ['@v' => $metrics['slow_requests']]),
      ],
      '#suffix' => '<p><a href="' . Url::fromRoute('d11_performance_optimizer.dashboard')->toString() . '">' . $this->t('Full dashboard →') . '</a></p>',
      '#cache' => [
        'max-age' => 300,
        'contexts' => ['user.permissions'],
      ],
    ];
  }

}
