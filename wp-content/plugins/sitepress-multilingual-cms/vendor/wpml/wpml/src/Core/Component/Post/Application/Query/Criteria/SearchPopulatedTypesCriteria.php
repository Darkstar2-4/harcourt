<?php

namespace WPML\Core\Component\Post\Application\Query\Criteria;

final class SearchPopulatedTypesCriteria {

  private $itemSectionIds = [];

  private $publicationStatus;

  private $languages;

  private $translationStatuses = [];


  public function __construct(
    SourceAndTargetLanguages $languages,
    array $itemSectionIds = [],
    ?string $publicationStatus = null,
    array $translationStatuses = []
  ) {
    $this->languages           = $languages;
    $this->itemSectionIds      = $itemSectionIds;
    $this->publicationStatus   = $publicationStatus;
    $this->translationStatuses = $translationStatuses;
  }


  public function getPublicationStatus() {
    return $this->publicationStatus;
  }


  public function getSourceLanguageCode(): string {
    return $this->languages->getSourceLanguageCode();
  }


  public function getTargetLanguageCodes(): array {
    return $this->languages->getTargetLanguageCodes();
  }


  public function getTranslationStatuses() {
    return $this->translationStatuses;
  }


  public function getItemSectionIds() {
    return $this->itemSectionIds;
  }


  public function getPostTypeIds(): array {
    return array_map(
      function ( $itemSectionId ) {
        return str_replace( 'post/', '', $itemSectionId );
      },
      array_filter(
        $this->itemSectionIds,
        function ( $itemSectionId ) {
          return strpos( $itemSectionId, 'post/' ) === 0;
        }
      )
    );
  }


}
