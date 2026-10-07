<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Calculator;

use WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Ideogram\PrepareContent as PrepareContentIdeogram;
use WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Letter\PrepareContent as PrepareContentLetter;
use WPML\Core\Component\WordsToTranslate\Domain\Config;
use WPML\Core\Component\WordsToTranslate\Domain\Item;
use WPML\Core\Component\WordsToTranslate\Domain\LastTranslation;

class WordsToTranslate {

  private static $freshWordCount = [];

  private $diff;

  private $count;

  private $prepareContentLetter;

  private $prepareContentIdeogram;


  public function __construct(
    Diff $diff,
    Count $count,
    PrepareContentLetter $prepareContentLetter,
    PrepareContentIdeogram $prepareContentIdeogram
  ) {
    $this->diff = $diff;
    $this->count = $count;
    $this->prepareContentLetter = $prepareContentLetter;
    $this->prepareContentIdeogram = $prepareContentIdeogram;
  }


  public function forLastTranslation( LastTranslation $lastTranslation, Item $original ) {
    $sourceLang = strtolower( $original->getSourceLang() );
    $prepare = $this->prepareContentLetter;

    $lastTranslationOriginalContent = $lastTranslation->getOriginalContent() ?? '';
    $isFreshTranslation = $lastTranslationOriginalContent === '';

    if ( $isFreshTranslation && isset( self::$freshWordCount[ $original->getId() ] ) ) {
      $lastTranslation->setWordsToTranslate( self::$freshWordCount[ $original->getId() ] );
      return;
    }

    $countFactor = 1;
    if ( isset( Config::LANGS[$sourceLang][Config::KEY_WORDS_PER_IDEOGRAM] ) ) {
      $prepare = $this->prepareContentIdeogram;
      $countFactor = Config::LANGS[$sourceLang][Config::KEY_WORDS_PER_IDEOGRAM];
    }

    $diff = $this->diff->diffArrays(
      $prepare->prepareForDiff( $lastTranslationOriginalContent ),
      $prepare->prepareForDiff( $original->getContent() ?? '' )
    );

    $count = (int) ( round( $this->count->wordsToTranslate( $diff ) * $countFactor ) );

    if ( $isFreshTranslation ) {
      self::$freshWordCount[ $original->getId() ] = $count;
    }

    $lastTranslation->setWordsToTranslate( $count );

  }


}
