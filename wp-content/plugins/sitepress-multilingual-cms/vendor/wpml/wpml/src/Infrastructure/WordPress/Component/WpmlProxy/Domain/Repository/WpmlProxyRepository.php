<?php

namespace WPML\Infrastructure\WordPress\Component\WpmlProxy\Domain\Repository;

use WPML\Core\Component\WpmlProxy\Domain\Repository\WpmlProxyRepositoryInterface;
use WPML\Core\Port\Persistence\OptionsInterface;

class WpmlProxyRepository implements WpmlProxyRepositoryInterface {

  const OPTION_NAME = 'wpml_proxy_enabled';

  private $options;


  public function __construct( OptionsInterface $options ) {
    $this->options = $options;
  }


  public function isEnabled(): bool {
    $isEnabled = $this->options->get( self::OPTION_NAME, false );

    return $isEnabled;
  }


  public function setIsEnabled( bool $isEnabled ) {
    $this->options->save( self::OPTION_NAME, $isEnabled ? 1 : 0 );
  }


}
