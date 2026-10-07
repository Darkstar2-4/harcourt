<?php

namespace WPML\Infrastructure\WordPress\SharedKernel\Server\Application;

use Error;
use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\SharedKernel\Component\Server\Domain\ServerInfoInterface;

class ServerInfo implements ServerInfoInterface {

  private $queryHandler;


  public function __construct( QueryHandlerInterface $queryHandler ) {
    $this->queryHandler = $queryHandler;
  }


  public function getPhpVersion(): string {
    return PHP_VERSION;
  }


  public function getConstant( string $name, $default = null ) {
    try {
      return defined( $name ) ? constant( $name ) : $default;
    } catch ( Error $e ) {
      return $default;
    }
  }


  public function isExtensionLoaded( string $name ): bool {
    return extension_loaded( $name );
  }


  public function getDbVersion() {
    return $this->queryHandler->querySingle( 'SELECT VERSION()' );
  }


  public function getWordPressVersion(): string {
    return $GLOBALS['wp_version'] ?? '';
  }


  public function getIniGet( string $key ) {
    return @ini_get( $key );
  }


  public function getOriginalIniGet( string $key ) {
    $array = ini_get_all();
    if ( $array === false || ! isset( $array[ $key ] )
         || ! isset( $array[ $key ]['global_value'] )
    ) {
      return false;
    }

    return $array[ $key ]['global_value'];
  }


}
