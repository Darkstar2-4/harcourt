<?php

namespace WPML\Core\Component\PostHog\Application\Cookies;

interface CookiesInterface {


  public function getDistinctId();


  public function getSessionId();


}
