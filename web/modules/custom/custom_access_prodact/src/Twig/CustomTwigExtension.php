<?php

declare(strict_types=1);

namespace Drupal\custom_access_prodact\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class CustomTwigExtension extends AbstractExtension {

  public function getFilters() {
    return [
      new TwigFilter('reverse', [$this, 'reverseString']),
    ];
  }

  public function reverseString($string) {
    return strrev($string);
  }
}
