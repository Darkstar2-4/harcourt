<?php

namespace WPML\Infrastructure\WordPress\CompositionRoot;

use WPML\PHP\Auryn\Injector;

class DIC {

  private $dic;


  public function __construct() {
    $this->dic = new Injector();
  }


  public function make( $classname, $args = [] ) {
    return $this->dic->make( $classname, $args );
  }


  public function share( $classnameOrObject ) {
    $this->dic->share( $classnameOrObject );
  }


  public function define( $name, array $args ) {
    $this->dic->define( $name, $args );
  }


  public function defineParam( $name, $value ) {
    $this->dic->defineParam( $name, $value );
  }


  public function alias( $interfaceName, $implementationName ) {
    $this->dic->alias( $interfaceName, $implementationName );
  }


}
