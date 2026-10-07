<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\StringBatch;

use WPML\Core\Component\WordsToTranslate\Domain\Item;

class StringBatch extends Item {

  private $strings;


  public function __construct(
    $id,
    $sourceLang,
    $strings
  ) {
    parent::__construct( $id, 'stringBatch', $sourceLang );
    $this->strings = $strings;
  }


  public function getStrings() {
    return $this->strings;
  }


  public function getWordsToTranslate( $langCode = null ) {
    $wordsToTranslate = 0;

    foreach ( $this->strings as $string ) {
      $wordsToTranslate += $string->getWordsToTranslate( $langCode );
    }

    return $wordsToTranslate;
  }


}
