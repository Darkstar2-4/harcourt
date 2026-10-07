<?php

namespace WPML\Core\Component\PostHog\Domain\Event;

interface EventInterface {


  public function getName(): string;


  public function getProperties(): array;


  public function addProperties( array $properties );


}
