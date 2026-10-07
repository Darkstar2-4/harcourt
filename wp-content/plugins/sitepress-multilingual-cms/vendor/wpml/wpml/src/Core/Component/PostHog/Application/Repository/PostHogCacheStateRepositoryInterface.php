<?php

namespace WPML\Core\Component\PostHog\Application\Repository;

interface PostHogCacheStateRepositoryInterface {


  public function getLastChecked(): ?int;


  public function setLastChecked( int $timestamp );


  public function isStale( int $ttlSeconds ): bool;


  public function acquireProcessingLock(): bool;


  public function releaseProcessingLock();


}
