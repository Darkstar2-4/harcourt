<?php

namespace WPML\Core\Component\StringPackage\Application\Query\Dto;

class PackageDefinitionDto {

  private $title;

  private $slug;

  private $plural;


  public function __construct( string $title, string $slug, string $plural ) {
    $this->title  = $title;
    $this->slug   = $slug;
    $this->plural = $plural;
  }


  public function getTitle(): string {
    return $this->title;
  }


  public function getSlug(): string {
    return $this->slug;
  }


  public function getPlural(): string {
    return $this->plural;
  }


}
