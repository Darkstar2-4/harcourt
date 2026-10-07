<?php

namespace WPML\Core\Component\Translation\Domain\TranslationBatch;

use WPML\Core\Component\Translation\Domain\Translation;
use WPML\Core\Component\Translation\Domain\TranslationType;

class Element {

  private $elementId;

  private $type;

  private $originalLanguageCode;

  private $existingTranslations;


  public function __construct(
    int $elementId,
    TranslationType $type,
    string $originalLanguageCode,
    array $existingTranslation = []
  ) {
    $this->elementId            = $elementId;
    $this->type                 = $type;
    $this->originalLanguageCode = $originalLanguageCode;
    $this->existingTranslations = $existingTranslation;
  }


  public function getElementId(): int {
    return $this->elementId;
  }


  public function getType(): TranslationType {
    return $this->type;
  }


  public function getOriginalLanguageCode(): string {
    return $this->originalLanguageCode;
  }


  public function getExistingTranslations(): array {
    return $this->existingTranslations;
  }


}
