<?php

namespace WPML\Core\Component\PostHog\Domain;

class RefreshSignatureValidator {

  const MESSAGE_PREFIX    = 'wpml-posthog-refresh:';
  const STALE_WINDOW_SECS = 300;


  public function validate(
    string $siteKey,
    int $timestamp,
    string $signature,
    int $now = 0
  ): bool {
    if ( $siteKey === '' ) {
      return false;
    }

    $now = $now ?: time();

    if ( abs( $now - $timestamp ) > self::STALE_WINDOW_SECS ) {
      return false;
    }

    $expected = hash_hmac( 'sha256', self::MESSAGE_PREFIX . $timestamp, $siteKey );

    return hash_equals( $expected, $signature );
  }


}
