<?php

namespace WPML\Infrastructure\WordPress\Component\ReportContentStats\Domain\Repository;

use WPML\Core\Component\ReportContentStats\Domain\Repository\EventReasonRepositoryInterface;
use WPML\Infrastructure\WordPress\Port\Persistence\Options;

class EventReasonRepository implements EventReasonRepositoryInterface {

    const OPTION_KEY = 'wpml-stats-event-reason';

    private $options;


  public function __construct( Options $options ) {
      $this->options = $options;
  }


  public function get() {
      $eventReason = $this->options->get( self::OPTION_KEY, null );

      return $eventReason;
  }


  public function set( string $reason ) {
      $this->options->save( self::OPTION_KEY, $reason );
  }


  public function clear() {
      $this->options->delete( self::OPTION_KEY );
  }


}
