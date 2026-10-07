<?php

namespace WPML\Core\Component\ReportContentStats\Domain;

class Retry {

  const DEFAULT_RETRY_INTERVAL_MINUTES = 30;

  private $attemptCount;

  private $lastAttemptTimestamp;


  public function __construct( int $attemptCount = 0, ?int $lastAttemptTimestamp = null ) {
    $this->attemptCount         = $attemptCount;
    $this->lastAttemptTimestamp = $lastAttemptTimestamp;
  }


  public function hasExceededMaxAttempts( int $maxAttempts ): bool {
    return $this->attemptCount >= $maxAttempts;
  }


  public function isInRetryMode(): bool {
    return $this->attemptCount > 0;
  }


  public function shouldRetryNow( int $intervalMinutes ): bool {
    if ( $this->attemptCount === 0 ) {
      return false;
    }
    if ( $this->lastAttemptTimestamp === null ) {
      return true;
    }
    $minutesSinceLastAttempt = intval( ceil( ( time() - $this->lastAttemptTimestamp ) / 60 ) );

    return $minutesSinceLastAttempt >= $intervalMinutes;
  }


  public function toArray(): array {
    return [
      'attempt_count'          => $this->attemptCount,
      'last_attempt_timestamp' => $this->lastAttemptTimestamp,
    ];
  }


}
