<?php

namespace WPML\PHP\Logger;


class DebugFileLogger implements LoggerInterface {

  private static $instance;


  public static function load( LoggerInterface $logger ) {
    self::$instance = $logger;
  }


  public static function getInstance(): LoggerInterface {
    if ( ! self::$instance ) {
      self::$instance = new DebugFileLogger();
    }

    return self::$instance;
  }




  private function log( $level, $message ) {
    if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
      return;
    }

    error_log( ' [' . $level . '] ' . $message );
  }


  public function error( $message ) {
    $this->log( 'error', $message );
  }


  public function notice( $message ) {
    $this->log( 'notice', $message );
  }


}
