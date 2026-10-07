<?php

namespace WPML\Infrastructure\WordPress\Component\PostHog\Application\Repository;

use WPML\Core\Component\PostHog\Application\Repository\PostHogRefreshRateLimitRepositoryInterface;

class PostHogRefreshRateLimitRepository implements PostHogRefreshRateLimitRepositoryInterface {

    const TRANSIENT_KEY = 'wpml_posthog_refresh_rate_limit';
    const RATE_LIMIT_TTL_SECONDS = 7200;


  public function isRateLimited(): bool {
      return (bool) get_transient( self::TRANSIENT_KEY );
  }


  public function setRateLimit(): void {
      set_transient( self::TRANSIENT_KEY, 1, self::RATE_LIMIT_TTL_SECONDS );
  }


}
