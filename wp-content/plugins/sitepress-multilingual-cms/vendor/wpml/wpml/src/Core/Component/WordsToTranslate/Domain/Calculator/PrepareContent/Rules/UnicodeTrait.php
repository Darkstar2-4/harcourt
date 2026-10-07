<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules;

trait UnicodeTrait {


  private function replaceUnicode( $text ) {
    $text = preg_replace( '/^\xEF\xBB\xBF/', '', $text ) ?? '';

    $text = preg_replace( '/^\xFE\xFF|\xFF\xFE/', '', $text ) ?? '';

    $text = str_replace( "\xC2\xA0", ' ', $text );

    $text = preg_replace( '/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', $text ) ?? '';

    return $text;
  }


}
