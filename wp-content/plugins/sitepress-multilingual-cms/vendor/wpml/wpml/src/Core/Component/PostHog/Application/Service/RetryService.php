<?php

namespace WPML\Core\Component\PostHog\Application\Service;

use WPML\Core\Component\PostHog\Application\Repository\RetryRepositoryInterface;
use WPML\Core\Component\PostHog\Domain\RetrySendDefaultRequestDomain;

class RetryService {

  private $retryRepository;


  public function __construct( RetryRepositoryInterface $retryRepository ) {
    $this->retryRepository = $retryRepository;
  }


  public function shouldRetry(): bool {
    return $this->getRetryDomain()->shouldRetry(
      $this->getRetryMaxAttempts(),
      $this->getRetryIntervalMinutes()
    );
  }


  public function hasExceededMaxAttempts(): bool {
    return $this->getRetryDomain()->hasExceededMaxAttempts( $this->getRetryMaxAttempts() );
  }


  public function isInRetryMode(): bool {
    return $this->getRetryDomain()->isInRetryMode();
  }


  public function incrementAttempt() {
    $currentRetryData = $this->retryRepository->get();
    $attemptCount     = $currentRetryData !== null ? $currentRetryData['attempt_count'] : 0;

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


  private function getRetryDomain(): RetrySendDefaultRequestDomain {
    $retryData            = $this->retryRepository->get();
    $attemptCount         = $retryData !== null ? $retryData['attempt_count'] : 0;
    $lastAttemptTimestamp = $retryData !== null ? $retryData['last_attempt_timestamp'] : null;

    return new RetrySendDefaultRequestDomain( $attemptCount, $lastAttemptTimestamp );
  }


  public function getRetryIntervalMinutes(): int {
    if ( defined( 'WPML_POSTHOG_RETRY_INTERVAL_MINUTES' ) ) {
      return intval( WPML_POSTHOG_RETRY_INTERVAL_MINUTES );
    }

    return RetrySendDefaultRequestDomain::DEFAULT_RETRY_INTERVAL_MINUTES;
  }


  public function getRetryMaxAttempts(): int {
    if ( defined( 'WPML_POSTHOG_RETRY_MAX_ATTEMPTS' ) ) {
      return intval( WPML_POSTHOG_RETRY_MAX_ATTEMPTS );
    }

    return RetrySendDefaultRequestDomain::MAX_ATTEMPTS;
  }


}
