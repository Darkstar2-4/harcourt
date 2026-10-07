<?php

namespace WPML\Core\Component\ReportContentStats\Domain;

class PostTypeStats {

  private $postTypeId;

  private $postsCount;

  private $charactersCount;

  private $translationCoverage;


  public function __construct(
    string $postTypeId,
    int $postsCount,
    int $charactersCount,
    array $translationCoverage
  ) {
    $this->postTypeId          = $postTypeId;
    $this->postsCount          = $postsCount;
    $this->charactersCount     = $charactersCount;
    $this->translationCoverage = $translationCoverage;
  }


  public function getPostTypeId(): string {
    return $this->postTypeId;
  }


  public function getPostsCount(): int {
    return $this->postsCount;
  }


  public function getCharactersCount(): int {
    return $this->charactersCount;
  }


  public function getTranslationCoverage(): array {
    return $this->translationCoverage;
  }


}
