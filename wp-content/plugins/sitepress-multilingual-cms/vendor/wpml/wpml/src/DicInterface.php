<?php

namespace WPML;

interface DicInterface {


  public function make( $classname, $args = [] );


  public function share( $classnameOrObject );


  public function define( $name, array $args );


  public function defineParam( $name, $value );


  public function alias( $interfaceName, $implementationName );


  public function delegate( $name, $factory );


}
