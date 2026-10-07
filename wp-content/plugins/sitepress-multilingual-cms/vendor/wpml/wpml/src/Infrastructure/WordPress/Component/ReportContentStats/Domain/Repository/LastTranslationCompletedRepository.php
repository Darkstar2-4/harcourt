<?php

namespace WPML\Infrastructure\WordPress\Component\ReportContentStats\Domain\Repository;

use WPML\Core\Component\ReportContentStats\Domain\Repository\LastTranslationCompletedRepositoryInterface;
use WPML\Infrastructure\WordPress\Port\Persistence\Options;

class LastTranslationCompletedRepository implements LastTranslationCompletedRepositoryInterface {

  const OPTION_KEY = 'wpml-stats-last-translation-completed';

  private $options;


  public function __construct( Options $options ) {
    $this->options = $options;
  }


  public function get() {
    $timestamp = $this->options->get( self::OPTION_KEY, null );

    return $timestamp;
  }


  public function update( int $timestamp ) {
    $this->options->save( self::OPTION_KEY, $timestamp );
  }


}
