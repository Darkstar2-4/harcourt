<?php

namespace WPML\Core\Component\PostHog\Domain\Event;

use WPML\PHP\Exception\RemoteException;

interface CaptureInterface {


  public function capture(
    string $apiKey,
    string $host,
    string $distinctId,
    string $sessionId,
    EventInterface $event,
    array $personProperties = []
  ): bool;


}
