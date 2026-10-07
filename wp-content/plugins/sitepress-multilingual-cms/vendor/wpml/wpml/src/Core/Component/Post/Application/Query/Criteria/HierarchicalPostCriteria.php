<?php

namespace WPML\Core\Component\Post\Application\Query\Criteria;

use WPML\PHP\ConstructableFromArrayInterface;
use WPML\PHP\ConstructableFromArrayTrait;

final class HierarchicalPostCriteria  implements ConstructableFromArrayInterface {

  use ConstructableFromArrayTrait;

  private $type;

  private $sourceLanguageCode;

  private $search;

  private $limit;

  private $offset;


  public function __construct(
    string $type,
    string $sourceLanguageCode,
    ?string $search = null,
    ?int $limit = null,
    ?int $offset = null
  ) {
    $this->type = $type;
    $this->sourceLanguageCode = $sourceLanguageCode;
    $this->search = $search;
    $this->limit = $limit;
    $this->offset = $offset;
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


  public function getType(): string {
    return $this->type;
  }


  public function getSourceLanguageCode(): string {
    return $this->sourceLanguageCode;
  }


}
