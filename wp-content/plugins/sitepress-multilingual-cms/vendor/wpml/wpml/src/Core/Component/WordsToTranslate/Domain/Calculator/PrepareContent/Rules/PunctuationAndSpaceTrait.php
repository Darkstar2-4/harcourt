<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules;

trait PunctuationAndSpaceTrait {


  protected function removePunctuationAndSpaces( $text ) {
    return preg_replace( "/[^\p{L}\p{N}]/u", '', $text ) ?? '';
  }


  protected function replacePunctuationExceptApostrophesBySpace( $text ) {
    $text = str_replace( ["’", "`"], "'", $text );

    $text = str_replace( " ' ", " ", $text );


    return preg_replace( "/[\s.,?!\/\\\\*\-_\+%$]+/u", ' ', $text ) ?? '';
  }


}
