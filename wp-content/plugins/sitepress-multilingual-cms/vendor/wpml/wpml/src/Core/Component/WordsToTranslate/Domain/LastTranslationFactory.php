<?php

namespace WPML\Core\Component\WordsToTranslate\Domain;

class LastTranslationFactory {

  private $lastTranslations = [];


  public function createForItem( Item $item, string $lang ) {
    $key = $item->getId() . $item->getType() . $lang;

    if ( ! isset( $this->lastTranslations[ $key ] ) ) {
      $this->lastTranslations[ $key ] = new LastTranslation( $lang );
    }

    return $this->lastTranslations[ $key ];
  }


}
