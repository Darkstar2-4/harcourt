<?php

namespace WPML\Core\Component\Translation\Domain\TranslationBatch;

use WPML\Core\Component\Translation\Domain\HowToHandleExistingTranslationType;
use WPML\PHP\DateTime;

class TranslationBatch {

  private $batchName;

  private $deadline;

  private $sourceLanguageCode;

  private $targetLanguages;

  private $howToHandleExisting;

  private $translationServiceExtraFields;


  public function __construct(
    string $batchName,
    string $sourceLanguageCode,
    array $targetLanguages,
    string $howToHandleExisting = HowToHandleExistingTranslationType::HANDLE_EXISTING_LEAVE,
    ?array $translationServiceExtraFields = null,
    ?DateTime $deadline = null
  ) {
    $this->batchName                     = $batchName;
    $this->sourceLanguageCode            = $sourceLanguageCode;
    $this->targetLanguages               = $targetLanguages;
    $this->howToHandleExisting           = $howToHandleExisting;
    $this->translationServiceExtraFields = $translationServiceExtraFields;
    $this->deadline                      = $deadline;
  }


  public function getBatchName(): string {
    return $this->batchName;
  }


  public function getDeadline() {
    return $this->deadline;
  }


  public function getSourceLanguageCode(): string {
    return $this->sourceLanguageCode;
  }


  public function getTargetLanguages(): array {
    return $this->targetLanguages;
  }


  public function getHowToHandleExisting(): string {
    return $this->howToHandleExisting;
  }


  public function getTranslationServiceExtraFields() {
    return $this->translationServiceExtraFields;
  }


  public function copyWithNewTargetLanguages( array $targetLanguages ): self {
    return new self(
      $this->batchName,
      $this->sourceLanguageCode,
      $targetLanguages,
      $this->howToHandleExisting,
      $this->translationServiceExtraFields,
      $this->deadline
    );
  }


}
