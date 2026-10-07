<?php

namespace WPML\Core\Component\WordsToTranslate\Domain;

use WPML\PHP\Exception\InvalidArgumentException;

class Provider {

  private $providers = [];


  public function __construct( $providers ) {
    $this->providers = $providers;
  }


  public function getByIdAndTypeForLangs( $id, $type, $langs, $freshTranslation = false ) {
    foreach ( $this->providers as $provider ) {
      if ( $item = $provider->getByIdAndTypeForLangs( $id, $type, $langs, $freshTranslation ) ) {
        return $item;
      }
    }

    throw new InvalidArgumentException(
      sprintf(
        'Item with id %d and type %s not found',
        $id,
        $type
      )
    );
  }


}
