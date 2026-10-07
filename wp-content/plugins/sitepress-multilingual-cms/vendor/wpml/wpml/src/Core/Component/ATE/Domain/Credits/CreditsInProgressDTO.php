<?php

namespace WPML\Core\Component\ATE\Domain\Credits;

class CreditsInProgressDTO {

  private $count;


  public function __construct( int $count ) {
    $this->count = $count;
  }


  public function getCount(): int {
    return $this->count;
  }


}
