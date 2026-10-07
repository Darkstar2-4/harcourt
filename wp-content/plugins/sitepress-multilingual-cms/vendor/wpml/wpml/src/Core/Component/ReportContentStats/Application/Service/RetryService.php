<?php

namespace WPML\Core\Component\ReportContentStats\Application\Service;

use WPML\Core\Component\ReportContentStats\Domain\Repository\RetryRepositoryInterface;
use WPML\Core\Component\ReportContentStats\Domain\Retry;

class RetryService {

  const MAX_RETRY_ATTEMPTS = 3;

  private $retryRepository;


  public function __construct(
    RetryRepositoryInterface $retryRepository
  ) {
    $this->retryRepository = $retryRepository;
  }


  public function shouldRetry(): bool {
    $retry = $this->getRetryDomain();

    if ( $retry->hasExceededMaxAttempts( self::MAX_RETRY_ATTEMPTS ) ) {
      return false;
    }

    return $retry->shouldRetryNow( $this->getRetryIntervalMinutes() );
  }


  public function hasExceededMaxAttempts(): bool {
    $retry = $this->getRetryDomain();

    return $retry->hasExceededMaxAttempts( self::MAX_RETRY_ATTEMPTS );
  }


  public function isInRetryMode(): bool {
    $retry = $this->getRetryDomain();

    return $retry->isInRetryMode();
  }


  public function incrementAttempt() {
    $currentData  = $this->retryRepository->get();
    $attemptCount = $currentData !== null ? $currentData['attempt_count'] : 0;

    $this->retryRepository->update(
      [
      'attempt_count'          => $attemptCount + 1,
      'last_attempt_timestamp' => time(),
       ]
    );
  }


  public function reset() {
    $this->retryRepository->delete();
  }


  private function getRetryDomain(): Retry {
    $retryData            = $this->retryRepository->get();
    $attemptCount         = $retryData !== null ? $retryData['attempt_count'] : 0;
    $lastAttemptTimestamp = $retryData !== null ? $retryData['last_attempt_timestamp'] : null;

    return new Retry( $attemptCount, $lastAttemptTimestamp );
  }


  public function getRetryIntervalMinutes(): int {
    if ( defined( 'WPML_STATS_RETRY_INTERVAL_MINUTES' ) ) {
      return intval( WPML_STATS_RETRY_INTERVAL_MINUTES );
    }

    return Retry::DEFAULT_RETRY_INTERVAL_MINUTES;
  }


}
