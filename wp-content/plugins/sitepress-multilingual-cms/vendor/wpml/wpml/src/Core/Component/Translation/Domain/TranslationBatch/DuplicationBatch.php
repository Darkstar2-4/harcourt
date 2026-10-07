<?php

namespace WPML\Core\Component\Translation\Domain\TranslationBatch;

class DuplicationBatch {

  private $batchName;

  private $sourceLanguageCode;

  private $targetLanguages = [];

  private $postIds = [];


  public function __construct(
    string $batchName,
    string $sourceLanguageCode,
    array $targetLanguages,
    array $postIds
  ) {
    $this->batchName          = $batchName;
    $this->sourceLanguageCode = $sourceLanguageCode;
    $this->targetLanguages    = $targetLanguages;
    $this->postIds            = $postIds;
  }


  public function getBatchName(): string {
    return $this->batchName;
  }


  public function getSourceLanguageCode(): string {
    return $this->sourceLanguageCode;
  }


  public function getTargetLanguages(): array {
    return $this->targetLanguages;
  }


  public function getPostIds(): array {
    return $this->postIds;
  }


}
