<?php

namespace WPML\Infrastructure\WordPress\Component\PostHog\Application\Repository;

use WPML\Core\Component\PostHog\Application\Repository\RetryRepositoryInterface;
use WPML\Core\Port\Persistence\OptionsInterface;

class RetryRepository implements RetryRepositoryInterface {

  const OPTION_KEY = 'wpml_posthog_default_request_retry';

  private $options;


  public function __construct( OptionsInterface $options ) {
    $this->options = $options;
  }


  public function get() {
    $retryData = $this->options->get( self::OPTION_KEY, null );

    return $retryData;
  }


  public function update( array $retryData ) {
    $this->options->save( self::OPTION_KEY, $retryData );
  }


  public function delete() {
    $this->options->delete( self::OPTION_KEY );
  }


}
