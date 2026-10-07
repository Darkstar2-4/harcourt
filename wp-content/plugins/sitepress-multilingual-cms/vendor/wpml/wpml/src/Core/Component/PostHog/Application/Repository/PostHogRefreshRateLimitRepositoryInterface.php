<?php

namespace WPML\Core\Component\PostHog\Application\Repository;

interface PostHogRefreshRateLimitRepositoryInterface {


  public function isRateLimited(): bool;


  public function setRateLimit(): void;


}
