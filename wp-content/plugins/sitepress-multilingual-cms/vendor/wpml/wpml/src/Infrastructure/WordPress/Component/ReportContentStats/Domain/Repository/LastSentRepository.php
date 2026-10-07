<?php

namespace WPML\Infrastructure\WordPress\Component\ReportContentStats\Domain\Repository;

use WPML\Core\Component\ReportContentStats\Domain\Repository\LastSentRepositoryInterface;
use WPML\Infrastructure\WordPress\Port\Persistence\Options;

class LastSentRepository implements LastSentRepositoryInterface {

  const OPTION_KEY = 'wpml-stats-last-sent';

  private $options;


  public function __construct( Options $options ) {
    $this->options = $options;
  }


  public function get() {
    $currentLastSent = $this->options->get( self::OPTION_KEY, null );

    return $currentLastSent;
  }


  public function update( int $lastSent ) {
    $this->options->save( self::OPTION_KEY, $lastSent );
  }


}
