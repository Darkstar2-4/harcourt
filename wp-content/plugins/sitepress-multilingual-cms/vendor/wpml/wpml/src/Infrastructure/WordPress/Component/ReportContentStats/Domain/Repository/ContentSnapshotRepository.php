<?php

namespace WPML\Infrastructure\WordPress\Component\ReportContentStats\Domain\Repository;

use WPML\Core\Component\ReportContentStats\Domain\Repository\ContentSnapshotRepositoryInterface;
use WPML\Infrastructure\WordPress\Port\Persistence\Options;

class ContentSnapshotRepository implements ContentSnapshotRepositoryInterface {

  const OPTION_KEY = 'wpml-stats-content-snapshot';

  private $options;


  public function __construct( Options $options ) {
    $this->options = $options;
  }


  public function get(): ?int {
    $count = $this->options->get( self::OPTION_KEY, false );

    return $count !== false ? intval( $count ) : null;
  }


  public function save( int $count ): void {
    $this->options->save( self::OPTION_KEY, $count );
  }


}
