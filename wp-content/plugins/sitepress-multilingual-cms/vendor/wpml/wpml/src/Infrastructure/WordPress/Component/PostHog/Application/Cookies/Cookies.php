<?php

namespace WPML\Infrastructure\WordPress\Component\PostHog\Application\Cookies;

use WPML\Core\Component\PostHog\Application\Cookies\CookiesInterface;

class Cookies implements CookiesInterface {

  const DISTINCT_ID_COOKIE_NAME = 'wpml_ph_distinct_id';
  const SESSION_ID_COOKIE_NAME = 'wpml_ph_session_id';


  public function getDistinctId() {
    $distinctId = $_COOKIE[ self::DISTINCT_ID_COOKIE_NAME ] ?? false;

    return $distinctId ? sanitize_text_field( $distinctId ) : false;
  }


  public function getSessionId() {
    $sessionId = $_COOKIE[ self::SESSION_ID_COOKIE_NAME ] ?? false;

    return $sessionId ? sanitize_text_field( $sessionId ) : false;
  }


}
