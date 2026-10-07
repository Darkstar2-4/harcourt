<?php

namespace WPML\Infrastructure\Composer;

class Node {
  const PACKAGE_NAME = 'wpml/wpml';


  public static function runNpmCommand( $command ) {
    $requiredNodeVersion = 'v20';
    $preInstalledNodeVersion = exec( "node -v" );
    $queryPath = 'vendor/' . self::PACKAGE_NAME;

    if ( strpos( $preInstalledNodeVersion, $requiredNodeVersion ) === false ) {
      $result = self::runWithNodeVersion(
        $requiredNodeVersion,
        "cd $queryPath && npm $command"
      );

      if ( ! $result ) {
        return false;
      }
    } else {
      exec( "cd $queryPath && npm $command" );
    }

    return true;
  }


  private static function runWithNodeVersion( $version, $command ) {
    $nvm_dir = getenv( 'NVM_DIR' );
    if ( ! $nvm_dir ) {
      echo "\nNVM_DIR not set. Aborting.\n";
      return false;
    }

    echo "\nTrying to switch to node $version.\n";

    $result = exec(
      '[ -s "$NVM_DIR/nvm.sh" ] '.
      '&& \. "$NVM_DIR/nvm.sh" ' .
      "&& nvm install $version && $command"
    );

    return ! empty( $result );
  }


}
