<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Ideogram;

use WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules\HTMLTrait;
use WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules\NinjaFormFieldsTrait;
use WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules\NumbersTrait;
use WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules\PunctuationAndSpaceTrait;
use WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules\ShortcodeInterface;
use WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules\UnicodeTrait;
use WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\RulesInterface;

class Rules implements RulesInterface {
  use HTMLTrait;
  use NumbersTrait;
  use PunctuationAndSpaceTrait;
  use NinjaFormFieldsTrait;
  use UnicodeTrait;

  private $shortcode;


  public function __construct( ShortcodeInterface $shortcode ) {
    $this->shortcode = $shortcode;
  }


  public function applyRules( string $content ) {
    $content = $this->removeHTMLExceptTranslatableAttributes( $content );

    $content = $this->removeNinjaFormFields( $content );

    $content = $this->shortcode->removeShortcodes( $content );

    $content = $this->removePunctuationAndSpaces( $content );

    $content = $this->removeStandaloneNumbers( $content );

    $content = $this->replaceUnicode( $content );

    return $content;
  }


}
