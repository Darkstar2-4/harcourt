<?php

namespace WPML\Core\Component\Post\Application\Query\Criteria;

final class SearchCriteria {

  private $type;

  private $title;

  private $publicationStatus;

  private $languages;

  private $translationStatuses;

  private $parentId;

  private $taxonomyId;

  private $termId;

  private $limit = 10;

  private $offset = 0;

  private $sortingCriteria;


  public function __construct(
    string $type,
    SourceAndTargetLanguages $languages,
    ?string $title = null,
    ?string $publicationStatus = null,
    array $translationStatuses = [],
    ?int $parentId = null,
    ?string $taxonomyId = null,
    ?int $termId = null,
    int $limit = 10,
    int $offset = 0,
    ?array $sorting = null
  ) {
    $this->type                = $type;
    $this->title               = $title;
    $this->publicationStatus   = $publicationStatus;
    $this->languages           = $languages;
    $this->translationStatuses = $translationStatuses;
    $this->parentId            = $parentId;
    $this->taxonomyId          = $taxonomyId;
    $this->termId              = $termId;
    $this->limit               = $limit;
    $this->offset              = $offset;
    $this->sortingCriteria     = $sorting ?
      new SortingCriteria( $sorting['by'], $sorting['order'] ) :
      null;
  }


  public function getType(): string {
    return $this->type;
  }


  public function getTitle() {
    return $this->title;
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


  public function getParentId() {
    return $this->parentId;
  }


  public function getTaxonomyId() {
    return $this->taxonomyId;
  }


  public function getTermId() {
    return $this->termId;
  }


  public function getLimit(): int {
    return $this->limit;
  }


  public function getSortingCriteria() {
    return $this->sortingCriteria;
  }


  public function setLimit( int $limit ) {
    $this->limit = $limit;
  }


  public function getOffset(): int {
    return $this->offset;
  }


  public function setOffset( int $offset ) {
    $this->offset = $offset;
  }


  public function setSortingCriteria( SortingCriteria $sortingCriteria ) {
    $this->sortingCriteria = $sortingCriteria;
  }


}
