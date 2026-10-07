<?php

namespace WPML\Infrastructure\Composer;

use Composer\Installer\PackageEvent;

class PostInstall {


  public static function run( $event ) {
    $event->getIO()->write( '----------------------------------------------' );
    $event->getIO()->write( '-- WPML++: Installing NPM Packages.' );
    $event->getIO()->write( '----------------------------------------------' );
    $event->getIO()->write( 'npm ci --cache .npm' );
    $cmd = Node::runNpmCommand( 'ci --cache .npm' );

    if ( ! $cmd ) {
      $event->getIO()->write( '----------------------------------------------' );
      $event->getIO()->write( '-- WPML++: NPM installation FAILED.' );
      $event->getIO()->write( '----------------------------------------------' );
      $event->getIO()->write( 'This propably happened because node 20 could not be installed.' );
      $event->getIO()->write( 'Make sure you have nvm installed and $NVM_DIR as env variable.' );
      $event->getIO()->write(
        'ALTERNATIVE: Run "npm install" and "npm run build" manually in ' .
        '"vendor/wpml/wpml" using node 20.'
      );

      return;
    }

    $event->getIO()->write( '----------------------------------------------' );
    $event->getIO()->write( '-- WPML++: Generating build files.' );
    $event->getIO()->write( '----------------------------------------------' );
    Node::runNpmCommand( 'run build' );

    $event->getIO()->write( '----------------------------------------------' );
    $event->getIO()->write( '-- WPML++: Completed.' );
    $event->getIO()->write( '----------------------------------------------' );
  }


}
