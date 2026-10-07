<?php

namespace WPML\Core\Port\Event;

abstract class Event {

  private $name;

  private $payload;


  public function __construct( string $name, array $payload = [] ) {
    $this->name    = $name;
    $this->payload = $payload;
  }


  public function getName(): string {
    return $this->name;
  }


  public function getPayload(): array {
    return $this->payload;
  }


}
