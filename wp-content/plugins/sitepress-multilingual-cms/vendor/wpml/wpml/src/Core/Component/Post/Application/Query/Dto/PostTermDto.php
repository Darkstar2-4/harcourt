<?php

namespace WPML\Core\Component\Post\Application\Query\Dto;

class PostTermDto {

  private $id;

  private $name;


  public function __construct(
    int $id,
    string $name
  ) {
    $this->id       = $id;
    $this->name    = $name;
  }


  public function getId(): int {
    return $this->id;
  }


  public function getName(): string {
    return $this->name;
  }


}
