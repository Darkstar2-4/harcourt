<?php

namespace WPML\Core\Component\Post\Application\Query\Dto;

class PostTaxonomyDto {

  private $id;

  private $name;

  private $postTypeIds;


  public function __construct(
    string $id,
    string $name,
    array $postTypeIds = []
  ) {
    $this->id       = $id;
    $this->name     = $name;
    $this->postTypeIds    = $postTypeIds;
  }


  public function getId(): string {
    return $this->id;
  }


  public function getName(): string {
    return $this->name;
  }


  public function getPostTypeIds(): array {
    return $this->postTypeIds;
  }


}
