<?php

declare(strict_types=1);

namespace Drupal\custom_access_prodact\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Twig extension providing the "reverse" string filter.
 */
class CustomTwigExtension extends AbstractExtension {

  /**
   * {@inheritdoc}
   */
  public function getFilters() {
    return [
      new TwigFilter('reverse', [$this, 'reverseString']),
    ];
  }

  /**
   * Reverses the given string.
   */
  public function reverseString($string) {
    return strrev($string);
  }

}
