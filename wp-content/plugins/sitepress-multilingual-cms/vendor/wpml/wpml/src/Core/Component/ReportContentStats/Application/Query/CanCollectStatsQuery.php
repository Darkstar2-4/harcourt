<?php

namespace WPML\Core\Component\ReportContentStats\Application\Query;

use WPML\Core\Port\Persistence\OptionsInterface;

class CanCollectStatsQuery implements CanCollectStatsQueryInterface {

  const REPO_NAME = 'wpml';
  const OPTION_KEY = 'otgs_share_local_components';

  private $options;


  public function __construct( OptionsInterface $options ) {
    $this->options = $options;
  }


  public function get(): bool {
    $allowedRepos = $this->options->get( self::OPTION_KEY, [] );

    return isset( $allowedRepos[ self::REPO_NAME ] ) && $allowedRepos[ self::REPO_NAME ];
  }


}
