<?php

namespace WPML\Core\Component\Post\Application\Query\Criteria;

use WPML\PHP\ConstructableFromArrayInterface;
use WPML\PHP\ConstructableFromArrayTrait;

final class TaxonomyTermCriteria implements ConstructableFromArrayInterface
{

    use ConstructableFromArrayTrait;

    private $taxonomyId;

    private $search;

    private $limit;

    private $offset;

    private $sourceLanguageCode;


  public function __construct(
        string $taxonomyId,
        string $sourceLanguageCode,
        ?string $search = null,
        ?int $limit = null,
        ?int $offset = null
    ) {
      $this->taxonomyId         = $taxonomyId;
      $this->search             = $search;
      $this->limit              = $limit;
      $this->offset             = $offset;
      $this->sourceLanguageCode = $sourceLanguageCode;
  }


  public function getTaxonomyId(): string {
      return $this->taxonomyId;
  }


  public function getSourceLanguageCode(): string {
      return $this->sourceLanguageCode;
  }


  public function getSearch() {
      return $this->search;
  }


  public function getLimit() {
      return $this->limit;
  }


  public function getOffset() {
      return $this->offset;
  }


}
