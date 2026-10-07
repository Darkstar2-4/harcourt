<?php

namespace WPML\Core\Component\Translation\Application\Service\Validator\Dto;

class ValidationResultDto {

  private $type;

  private $valid;


  public function __construct( string $type, bool $valid ) {
    $this->type  = $type;
    $this->valid = $valid;
  }


  public function toArray(): array {
    return [
      'type'  => $this->type,
      'valid' => $this->valid,
    ];
  }


}
