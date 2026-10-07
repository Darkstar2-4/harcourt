<?php

namespace WPML\Core\Component\Translation\Domain\TranslationMethod;

use WPML\Core\SharedKernel\Component\Translation\Domain\TranslationMethod\TargetLanguageMethodType;

class LocalTranslatorMethod implements TranslationMethodInterface {

  private $translatorId;

  private $targetLanguageCode;


  public function __construct( int $translatorId, string $targetLanguageCode ) {
    $this->translatorId       = $translatorId;
    $this->targetLanguageCode = $targetLanguageCode;
  }


  public function get() {
    return TargetLanguageMethodType::LOCAL_TRANSLATOR;
  }


  public function getTranslatorId(): int {
    return $this->translatorId;
  }


  public function getTargetLanguageCode(): string {
    return $this->targetLanguageCode;
  }


}
