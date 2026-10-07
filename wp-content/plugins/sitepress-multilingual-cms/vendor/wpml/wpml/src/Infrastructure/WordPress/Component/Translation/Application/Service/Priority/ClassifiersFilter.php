<?php

namespace WPML\Infrastructure\WordPress\Component\Translation\Application\Service\Priority;

use WPML\Core\Component\Translation\Application\Service\Priority\ClassifiersFilterInterface;

class ClassifiersFilter implements ClassifiersFilterInterface {

    const FILTER_CLASSIFIERS = 'wpml_translation_priority_classifiers';


  public function filter( array $classifiers ): array {
      $filtered = apply_filters( self::FILTER_CLASSIFIERS, $classifiers );

      return $filtered;
  }


}
