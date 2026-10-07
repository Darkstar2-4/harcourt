<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules;

trait NumbersTrait {


  private function removeStandaloneNumbers( $text ) {
    return preg_replace( '/\b\d+\b/u', '', $text ) ?? '';
  }


}
