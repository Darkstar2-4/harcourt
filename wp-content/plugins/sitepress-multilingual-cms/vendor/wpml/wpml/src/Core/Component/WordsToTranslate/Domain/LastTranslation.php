<?php

namespace WPML\Core\Component\WordsToTranslate\Domain;

class LastTranslation {

  private $langCode;

  private $originalContent;

  private $wordsToTranslate;

  private $diffWordsToOriginal;


  public function __construct(
    string $langCode
  ) {
    $this->langCode = $langCode;
  }


  public function getLangCode() {
    return $this->langCode;
  }


  public function setOriginalContent( string $content ) {
    $this->originalContent = $content;
  }


  public function getOriginalContent() {
    return $this->originalContent;
  }


  public function setWordsToTranslate( $wordsToTranslate ) {
    $this->wordsToTranslate = $wordsToTranslate;
  }


  public function getWordsToTranslate() {
    return $this->wordsToTranslate;
  }


  public function setDiffWordsToOriginal( $diff ) {
    $this->diffWordsToOriginal = $diff;
  }


  public function getDiffWordsToOriginal() {
    return $this->diffWordsToOriginal;
  }


}
