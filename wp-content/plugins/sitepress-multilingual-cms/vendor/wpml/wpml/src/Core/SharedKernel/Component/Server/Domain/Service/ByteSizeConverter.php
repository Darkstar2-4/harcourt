<?php

namespace WPML\Core\SharedKernel\Component\Server\Domain\Service;

class ByteSizeConverter {


  public function toBytes( $val ) {
    $val = trim( (string) $val );

    $exponents = array(
      'k' => 1,
      'm' => 2,
      'g' => 3,
    );

    $last = strtolower( substr( $val, - 1 ) );

    if ( ! is_numeric( $last ) ) {
      $val = (int) substr( $val, 0, - 1 );

      if ( array_key_exists( $last, $exponents ) ) {
        $val *= pow( 1024, $exponents[ $last ] );
      }
    } else {
      $val = (int) $val;
    }

    return (int) $val;
  }


}
