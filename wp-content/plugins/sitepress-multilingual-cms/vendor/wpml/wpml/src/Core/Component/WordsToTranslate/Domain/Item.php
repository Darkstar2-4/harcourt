<?php

namespace WPML\Core\Component\WordsToTranslate\Domain;

class Item {

  protected $id;

  protected $type;

  protected $sourceLang;

  protected $content;

  protected $lastTranslations = [];


  public function __construct(
    int $id,
    string $type,
    string $sourceLang
  ) {
    $this->id = $id;
    $this->type = $type;
    $this->sourceLang = $sourceLang;
  }


  public function getId() {
    return $this->id;
  }


  public function getType() {
    return $this->type;
  }


  public function getSourceLang() {
    return $this->sourceLang;
  }


  public function setContent( string $content ) {
    $this->content = $content;
  }


  public function getContent() {
    return $this->content;
  }


  public function addLastTranslation( LastTranslation $lastTranslation ) {
     $this->lastTranslations[ $lastTranslation->getLangCode() ] = $lastTranslation;
  }


  public function getLastTranslations() {
    return $this->lastTranslations;
  }


  public function getWordsToTranslate( $langCode = null ) {
    if ( $langCode !== null ) {
      if ( ! isset( $this->lastTranslations[ $langCode ] ) ) {
        return 0;
      }

      return $this->lastTranslations[ $langCode ]->getWordsToTranslate() ?? 0;
    }

    $wordsToTranslate = 0;

    foreach ( $this->lastTranslations as $lastTranslation ) {
      $wordsToTranslate += $lastTranslation->getWordsToTranslate() ?? 0;
    }

    return $wordsToTranslate;
  }


}
