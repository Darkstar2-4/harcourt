<?php

namespace WPML\Core\SharedKernel\Component\Translator\Domain;

class LanguagePair {

  private $from;

  private $to;


  public function __construct( string $from, array $to ) {
    $this->from = $from;
    $this->to   = $to;
  }


  public function getFrom(): string {
    return $this->from;
  }


  public function getTo(): array {
    return $this->to;
  }


  public function toArray (): array {
    return [
      'from' => $this->getFrom(),
      'to'   => $this->getTo(),
    ];
  }


}
