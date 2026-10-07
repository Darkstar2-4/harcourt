<?php

namespace WPML\Core\Component\PostHog\Application\Repository;

interface PostHogStateRepositoryInterface {


  public function isEnabled(): bool;


  public function setIsEnabled( bool $isEnabled );


  public function getTrackingMode(): string;


  public function setTrackingMode( string $mode );


}
