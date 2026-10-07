<?php

namespace WPML\Core\Component\Translation\Application\Service\Dto;

use WPML\PHP\ConstructableFromArrayInterface;
use WPML\PHP\ConstructableFromArrayTrait;
use WPML\PHP\Exception\InvalidArgumentException;

final class TargetLanguageMethodDto implements ConstructableFromArrayInterface {
  use ConstructableFromArrayTrait;

  private $targetLanguageCode;

  private $translationMethod;

  private $translatorId;


  public function __construct(
    string $targetLanguageCode,
    string $translationMethod,
    ?int $translatorId = null
  ) {
    $this->targetLanguageCode = $targetLanguageCode;
    $this->translationMethod  = $translationMethod;
    $this->translatorId       = $translatorId;
  }


  public function getTargetLanguageCode(): string {
    return $this->targetLanguageCode;
  }


  public function getTranslationMethod(): string {
    return $this->translationMethod;
  }


  public function getTranslatorId() {
    return $this->translatorId;
  }


}
