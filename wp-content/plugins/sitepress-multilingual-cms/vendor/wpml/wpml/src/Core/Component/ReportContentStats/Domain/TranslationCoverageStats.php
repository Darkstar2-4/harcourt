<?php

namespace WPML\Core\Component\ReportContentStats\Domain;

class TranslationCoverageStats {

  private $postType;

  private $languageCode;

  private $translatedOriginalContentCharsCount;

  private $translatedOriginalContentCount;


  public function __construct(
      string $postType,
      string $languageCode,
      int $translatedOriginalContentCharsCount,
      int $translatedOriginalContentCount
  ) {
    $this->postType                            = $postType;
    $this->languageCode                        = $languageCode;
    $this->translatedOriginalContentCharsCount = $translatedOriginalContentCharsCount;
    $this->translatedOriginalContentCount      = $translatedOriginalContentCount;
  }


  public function getPostType(): string {
    return $this->postType;
  }


  public function getLanguageCode(): string {
    return $this->languageCode;
  }


  public function getTranslatedOriginalContentCharsCount(): int {
    return $this->translatedOriginalContentCharsCount;
  }


  public function getTranslatedOriginalContentCount(): int {
    return $this->translatedOriginalContentCount;
  }


}
