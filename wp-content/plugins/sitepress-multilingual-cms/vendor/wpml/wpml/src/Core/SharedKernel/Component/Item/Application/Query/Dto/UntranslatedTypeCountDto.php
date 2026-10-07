<?php

namespace WPML\Core\SharedKernel\Component\Item\Application\Query\Dto;

class UntranslatedTypeCountDto {

  private $namePlural;

  private $nameSingular;

  private $count;

  private $kind;

  private $type;


  public function __construct(
    string $namePlural,
    string $nameSingular,
    int $count,
    $kind,
    string $type = ''
  ) {
    $this->namePlural   = $namePlural;
    $this->nameSingular = $nameSingular;
    $this->count        = $count;
    $this->kind         = $kind;
    $this->type         = $type;
  }


  public function getNamePlural(): string {
    return $this->namePlural;
  }


  public function getNameSingular(): string {
    return $this->nameSingular;
  }


  public function getCount(): int {
    return $this->count;
  }


  public function toArray(): array {
    return [
      'namePlural'   => $this->namePlural,
      'nameSingular' => $this->nameSingular,
      'count'        => $this->count,
      'kind'         => $this->kind,
      'type'         => $this->type,
    ];
  }


}
