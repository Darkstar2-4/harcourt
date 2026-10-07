<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Job;

use WPML\Core\Component\WordsToTranslate\Domain\Item;

class Job extends Item {

  private $targetLang;

  private $item;

  private $isAutomatic;

  private $atePreviousJobIds;

  private $translationEngineCostsPerWord;


  public function __construct(
    $id,
    $sourceLang,
    $targetLang,
    $item,
    $isAutomatic,
    $atePreviousJobIds,
    $translationEngineCostsPerWord
  ) {
    $this->id = $id;
    $this->type = 'job';
    $this->sourceLang = $sourceLang;
    $this->targetLang = $targetLang;
    $this->item = $item;
    $this->isAutomatic = $isAutomatic;
    $this->atePreviousJobIds = $atePreviousJobIds;
    $this->translationEngineCostsPerWord = $translationEngineCostsPerWord;
  }


  public function getTargetLang() {
    return $this->targetLang;
  }


  public function getItem() {
    return $this->item;
  }


  public function getId() {
    return $this->id;
  }


  public function isAutomatic() {
    return $this->isAutomatic;
  }


  public function getPreviousAteJobIds() {
    return $this->atePreviousJobIds;
  }


  public function getWordsToTranslate( $langCode = null ) {
    return $this->item->getWordsToTranslate();
  }


  public function getAutomaticTranslationCosts() {
    if ( $this->translationEngineCostsPerWord === false ) {
      return false;
    }
    return $this->item->getWordsToTranslate() * $this->translationEngineCostsPerWord;
  }


}
