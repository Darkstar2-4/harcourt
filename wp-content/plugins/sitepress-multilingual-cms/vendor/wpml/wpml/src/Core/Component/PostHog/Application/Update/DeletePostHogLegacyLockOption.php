<?php

namespace WPML\Core\Component\PostHog\Application\Update;

use WPML\Core\Port\Persistence\OptionsInterface;
use WPML\Core\Port\Update\UpdateInterface;

class DeletePostHogLegacyLockOption implements UpdateInterface {

    const LEGACY_OPTION_KEY = 'wpml_posthog_default_request_sent';

    private $options;


  public function __construct( OptionsInterface $options ) {
      $this->options = $options;
  }


  public function update() {
      $this->options->delete( self::LEGACY_OPTION_KEY );
      return true;
  }


}
