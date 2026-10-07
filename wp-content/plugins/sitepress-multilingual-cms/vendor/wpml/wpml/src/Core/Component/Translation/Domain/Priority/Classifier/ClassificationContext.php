<?php

namespace WPML\Core\Component\Translation\Domain\Priority\Classifier;

class ClassificationContext {

    private $homePageId;

    private $parentMap;

    private $homepageSubtreeDepths;

    private $alreadyClassifiedIds;

    private $stringDomainPriorities;

    private $stringContextPriorities;

    private $cptPriorities;


  public function __construct(
        $homePageId = null,
        array $parentMap = [],
        array $homepageSubtreeDepths = [],
        array $alreadyClassifiedIds = [],
        array $stringDomainPriorities = [],
        array $stringContextPriorities = [],
        array $cptPriorities = []
    ) {
      $this->homePageId              = $homePageId;
      $this->parentMap               = $parentMap;
      $this->homepageSubtreeDepths   = $homepageSubtreeDepths;
      $this->alreadyClassifiedIds    = $alreadyClassifiedIds;
      $this->stringDomainPriorities  = $stringDomainPriorities;
      $this->stringContextPriorities = $stringContextPriorities;
      $this->cptPriorities           = $cptPriorities;
  }


  public function getHomePageId() {
      return $this->homePageId;
  }


  public function getParentMap(): array {
      return $this->parentMap;
  }


  public function getHomepageSubtreeDepths(): array {
      return $this->homepageSubtreeDepths;
  }


  public function getAlreadyClassifiedIds(): array {
      return $this->alreadyClassifiedIds;
  }


  public function getStringDomainPriorities(): array {
      return $this->stringDomainPriorities;
  }


  public function getStringContextPriorities(): array {
      return $this->stringContextPriorities;
  }


  public function getCptPriorities(): array {
      return $this->cptPriorities;
  }


  public function isHomePage( int $postId ): bool {
      return $this->homePageId !== null && $this->homePageId === $postId;
  }


  public function isInHomepageSubtree( int $postId ): bool {
      return isset( $this->homepageSubtreeDepths[ $postId ] );
  }


  public function getHomepageSubtreeDepth( int $postId ) {
      return $this->homepageSubtreeDepths[ $postId ] ?? null;
  }


  public function isAlreadyClassified( int $itemId ): bool {
      return in_array( $itemId, $this->alreadyClassifiedIds, true );
  }


  public function withClassifiedId( int $itemId ): self {
      $newClassifiedIds   = $this->alreadyClassifiedIds;
      $newClassifiedIds[] = $itemId;

      return new self(
        $this->homePageId,
        $this->parentMap,
        $this->homepageSubtreeDepths,
        $newClassifiedIds,
        $this->stringDomainPriorities,
        $this->stringContextPriorities,
        $this->cptPriorities
      );
  }


  public function getStringDomainPriority( string $domain ): int {
      return $this->stringDomainPriorities[ $domain ] ?? PHP_INT_MAX;
  }


  public function getStringContextPriority( string $context ): int {
      return $this->stringContextPriorities[ $context ] ?? PHP_INT_MAX;
  }


  public function getCptPriority( string $postType ): int {
      return $this->cptPriorities[ $postType ] ?? PHP_INT_MAX;
  }


  public function getHierarchyDepthFromRoot( int $postId ): int {
      $depth     = 0;
      $currentId = $postId;
      $visited   = [];

    while ( isset( $this->parentMap[ $currentId ] ) && $this->parentMap[ $currentId ] !== 0 ) {
      if ( isset( $visited[ $currentId ] ) ) {
        break;
      }
        $visited[ $currentId ] = true;
        $currentId             = $this->parentMap[ $currentId ];
        $depth++;
    }

      return $depth;
  }


}
