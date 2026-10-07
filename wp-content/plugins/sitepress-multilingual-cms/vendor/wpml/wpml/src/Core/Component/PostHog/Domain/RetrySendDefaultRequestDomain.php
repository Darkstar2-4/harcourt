<?php

namespace WPML\Core\Component\PostHog\Domain;

class RetrySendDefaultRequestDomain {

  const MAX_ATTEMPTS = 3;
  const DEFAULT_RETRY_INTERVAL_MINUTES = 30;

  private $attemptCount;

  private $lastAttemptTimestamp;


  public function __construct( int $attemptCount, $lastAttemptTimestamp ) {
    $this->attemptCount         = $attemptCount;
    $this->lastAttemptTimestamp = $lastAttemptTimestamp;
  }


  public function hasExceededMaxAttempts( int $maxAttempts ): bool {
    return $this->attemptCount >= $maxAttempts;
  }


  public function isInRetryMode(): bool {
    return $this->attemptCount > 0;
  }


  public function shouldRetry( int $maxAttempts, int $intervalInMinutes ): bool {
    if ( $this->attemptCount === 0 ) {
      return false;
    }

    if ( $this->hasExceededMaxAttempts( $maxAttempts ) ) {
      return false;
    }

    if ( $this->lastAttemptTimestamp === null ) {
      return true;
    }

    $minutesSinceLastAttempt = intval( ceil( ( time() - $this->lastAttemptTimestamp ) / 60 ) );

    return $minutesSinceLastAttempt >= $intervalInMinutes;
  }


}
