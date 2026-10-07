<?php

namespace WPML\Core\Component\PostHog\Application\Repository;

interface PostHogDefaultRequestSentRepositoryInterface {


  public function isSent(): bool;


  public function tryAcquireLock(): bool;


  public function setIsSent( bool $isSent );


  public function delete();


}
