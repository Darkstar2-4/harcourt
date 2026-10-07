<?php

namespace WPML\Core\Component\Post\Domain;

class PublicationStatus {

  private $value;

  private $label;


  public function __construct( string $value, string $label ) {
    $this->value = $value;
    $this->label = $label;
  }


  public function getValue(): string {
    return $this->value;
  }


  public function getLabel(): string {
    return $this->label;
  }


}
