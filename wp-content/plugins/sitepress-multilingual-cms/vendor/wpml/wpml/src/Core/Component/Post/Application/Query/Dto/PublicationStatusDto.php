<?php

namespace WPML\Core\Component\Post\Application\Query\Dto;

use WPML\PHP\ConstructableFromArrayInterface;
use WPML\PHP\ConstructableFromArrayTrait;

final class PublicationStatusDto implements ConstructableFromArrayInterface {
  use ConstructableFromArrayTrait;

  private $id;

  private $label;


  public function __construct( string $id, string $label ) {
    $this->id = $id;
    $this->label = $label;
  }


  public function getId(): string {
    return $this->id;
  }


  public function getLabel(): string {
    return $this->label;
  }


  public function toArray() {
    return [
      'id' => $this->id,
      'label' => $this->label,
    ];
  }


}
