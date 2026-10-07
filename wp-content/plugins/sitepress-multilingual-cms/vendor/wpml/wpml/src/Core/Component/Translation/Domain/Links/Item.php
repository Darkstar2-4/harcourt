<?php

namespace WPML\Core\Component\Translation\Domain\Links;

class Item {

  private $id;

  private $type;

  private $content;

  private $excerpt;

  private $languageCode;

  private $idOriginal;

  private $isDeleted = false;

  private $isPublished = false;

  private $isPublishable = false;

  private $gotPublished = false;

  private $linkHasChanged = false;

  private $nameBefore;


  public function __construct(
    int $id,
    string $type,
    string $content = '',
    string $excerpt = '',
    ?string $languageCode = null,
    ?int $id_original = null
  ) {
    $this->id           = $id;
    $this->type         = $type;
    $this->content      = $content;
    $this->excerpt      = $excerpt;
    $this->idOriginal   = $id_original;
    $this->languageCode = $languageCode;
  }


  public function getId(): int {
    return $this->id;
  }


  public function getType() {
    return $this->type;
  }


  public function setContent( string $content ) {
    $this->content = $content;
  }


  public function setExcerpt( string $excerpt ) {
    $this->excerpt = $excerpt;
  }


  public function getContent() {
    return $this->content;
  }


  public function getExcerpt() {
    return $this->excerpt;
  }


  public function setLanguageCode( string $languageCode ) {
    $this->languageCode = $languageCode;
  }


  public function getLanguageCode() {
    return $this->languageCode;
  }


  public function setIdOriginal( int $idOriginal ) {
    $this->idOriginal = $idOriginal;
  }


  public function getIdOriginal() {
    return $this->idOriginal;
  }


  public function isOriginal(): bool {
    return $this->idOriginal === null;
  }


  public function markAsDeleted() {
    $this->isDeleted = true;
  }


  public function isDeleted(): bool {
    return $this->isDeleted;
  }


  public function markLinkAsChanged( ?string $nameBefore = null ) {
    $this->linkHasChanged = true;
    $this->nameBefore = $nameBefore;
  }


  public function linkHasChanged(): bool {
    return $this->linkHasChanged;
  }


  public function getNameBefore() {
    return $this->nameBefore;
  }


  public function canLinkToOtherItems(): bool {
    return trim( $this->content ) !== '' || trim( $this->excerpt ) !== '';
  }


  public function markAsPublished() {
    $this->isPublished = true;
  }


  public function isPublished(): bool {
    return $this->isPublished;
  }


  public function markAsPublishable() {
    $this->isPublishable = true;
  }


  public function isPublishable(): bool {
    return $this->isPublished || $this->isPublishable;
  }


  public function markAsGotPublished() {
    $this->gotPublished = true;
  }


  public function gotPublished(): bool {
    return $this->gotPublished;
  }


}
