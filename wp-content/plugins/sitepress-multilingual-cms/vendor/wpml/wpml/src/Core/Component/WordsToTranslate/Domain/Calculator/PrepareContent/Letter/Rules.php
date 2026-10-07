<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Letter;

use WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules\HTMLTrait;
use WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules\NinjaFormFieldsTrait;
use WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules\NumbersTrait;
use WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules\OptionalPluralTrait;
use WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules\PunctuationAndSpaceTrait;
use WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules\ShortcodeInterface;
use WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules\UnicodeTrait;
use WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\RulesInterface;

class Rules implements RulesInterface {
  use HTMLTrait;
  use NumbersTrait;
  use PunctuationAndSpaceTrait;
  use OptionalPluralTrait;
  use NinjaFormFieldsTrait;
  use UnicodeTrait;

  private $shortcode;


  public function __construct( ShortcodeInterface $shortcode ) {
    $this->shortcode = $shortcode;
  }


  public function applyRules( string $content ) {
    $content = $this->removeHTMLExceptTranslatableAttributes( $content );

    $content = $this->shortcode->removeShortcodes( $content );

    $content = $this->removeOptionalPlural( $content );

    $content = $this->removeNinjaFormFields( $content );

    $content = $this->replacePunctuationExceptApostrophesBySpace( $content );

    $content = $this->removeStandaloneNumbers( $content );

    $content = $this->replaceUnicode( $content );

    $content = $this->allToLowerCase( $content );

    return $content;
  }


  private function allToLowerCase( $text ) {
    return mb_strtolower( $text, 'UTF-8' );
  }


}
