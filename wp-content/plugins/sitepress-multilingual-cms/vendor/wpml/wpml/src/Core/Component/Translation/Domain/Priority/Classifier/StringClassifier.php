<?php

namespace WPML\Core\Component\Translation\Domain\Priority\Classifier;

use WPML\Core\Component\Translation\Domain\Priority\JobPriority;
use WPML\Core\Component\Translation\Domain\Priority\PrioritizableItem;
use WPML\Core\Component\Translation\Domain\Priority\Rank;
use WPML\Core\Component\Translation\Domain\Priority\Tier;

class StringClassifier implements TierClassifierInterface {


  public function canClassify( PrioritizableItem $item, ClassificationContext $context ): bool {
      return $item->getType()->isString() || $item->getType()->isPackage();
  }


  public function classify( PrioritizableItem $item, ClassificationContext $context ): JobPriority {
      $domainPriority  = $context->getStringDomainPriority( $item->getStringDomain() ?? '' );
      $contextPriority = $context->getStringContextPriority( $item->getStringContext() ?? '' );
      $alphabeticalKey = $this->getAlphabeticalSortKey( $item->getStringContext() ?? '' );

      return new JobPriority(
        $item->getId(),
        Tier::strings(),
        new Rank( [ $domainPriority, $contextPriority, $alphabeticalKey ] )
      );
  }


  private function getAlphabeticalSortKey( string $value ): int {
    if ( $value === '' ) {
        return PHP_INT_MAX;
    }

      $normalized = strtolower( substr( $value, 0, 4 ) );
      $key        = 0;
      $multiplier = 1;

    for ( $i = min( 3, strlen( $normalized ) - 1 ); $i >= 0; $i-- ) {
        $key        += ord( $normalized[ $i ] ) * $multiplier;
        $multiplier *= 256;
    }

      return $key;
  }


}
