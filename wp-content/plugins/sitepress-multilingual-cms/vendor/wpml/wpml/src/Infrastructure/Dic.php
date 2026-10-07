<?php

namespace WPML\Infrastructure;

use WPML\DicInterface;
use WPML\PHP\Auryn\ConfigException;
use WPML\PHP\Auryn\Injector;

class Dic implements DicInterface {

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


  public function delegate( $name, $factory ) {
    $this->dic->delegate( $name, $factory );
  }


}
