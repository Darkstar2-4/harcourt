<?php

namespace WPML\Core\Component\Translation\Domain\Priority;

class Rank {

    private $values;


  public function __construct( array $values ) {
      $this->values = $values;
  }


  public function getValues(): array {
      return $this->values;
  }


  public function compareTo( Rank $other ): int {
      $thisValues  = $this->values;
      $otherValues = $other->getValues();
      $maxLength   = max( count( $thisValues ), count( $otherValues ) );

    for ( $i = 0; $i < $maxLength; $i++ ) {
        $thisValue  = $thisValues[ $i ] ?? 0;
        $otherValue = $otherValues[ $i ] ?? 0;

      if ( $thisValue < $otherValue ) {
        return -1;
      }
      if ( $thisValue > $otherValue ) {
          return 1;
      }
    }

      return 0;
  }


}
