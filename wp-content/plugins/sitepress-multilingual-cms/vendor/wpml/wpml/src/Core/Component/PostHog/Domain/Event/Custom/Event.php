<?php

namespace WPML\Core\Component\PostHog\Domain\Event\Custom;

use WPML\Core\Component\PostHog\Domain\Event\EventInterface;

class Event implements EventInterface {

  private $name;

  private $properties;


  public function __construct( string $name, array $properties ) {
    $this->name       = $name;
    $this->properties = $properties;
  }


  public function getName(): string {
    return $this->name;
  }


  public function getProperties(): array {
    return $this->properties;
  }


  public function addProperties( array $properties ) {
    $this->properties = array_merge( $this->properties, $properties );
  }


}
