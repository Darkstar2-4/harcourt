<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Calculator;

class Count {


  public function wordsToTranslate( $diff ) {
    $wordsToTranslate = 0;
    foreach ( $diff as $part ) {
      if ( isset( $part[ Diff::DIFF_KEY_ADDED ] ) ) {
        $wordsToTranslate += count( $part[ Diff::DIFF_KEY_ADDED ] );
      }
    }
    return $wordsToTranslate;
  }


}
