<?php

namespace WPML\Infrastructure\WordPress\Component\Translation\Domain\PreviousState;

use WPML\Core\Component\Translation\Domain\PreviousState\DataCompressInterface;

class OnlyDataSerialization implements DataCompressInterface {


  public function compress( array $data ): string {
    return serialize( $data );
  }


  public function decompress( string $data ): array {
    if ( empty( $data ) ) {
      return [];
    }

    $unserialized = @unserialize( $data );
    if ( is_array( $unserialized ) ) {
      return $unserialized;
    }

    return [];
  }


}
