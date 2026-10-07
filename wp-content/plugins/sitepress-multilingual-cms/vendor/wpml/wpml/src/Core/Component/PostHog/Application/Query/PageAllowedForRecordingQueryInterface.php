<?php

namespace WPML\Core\Component\PostHog\Application\Query;

interface PageAllowedForRecordingQueryInterface {


  public function isAllowed(): bool;


}
