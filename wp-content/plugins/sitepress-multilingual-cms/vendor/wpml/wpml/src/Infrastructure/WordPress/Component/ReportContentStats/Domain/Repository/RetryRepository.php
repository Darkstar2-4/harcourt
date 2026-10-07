<?php

namespace WPML\Infrastructure\WordPress\Component\ReportContentStats\Domain\Repository;

use WPML\Core\Component\ReportContentStats\Domain\Repository\RetryRepositoryInterface;
use WPML\Infrastructure\WordPress\Port\Persistence\Options;

class RetryRepository implements RetryRepositoryInterface {

    const OPTION_KEY = 'wpml-stats-retry-data';

    private $options;


  public function __construct( Options $options ) {
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
