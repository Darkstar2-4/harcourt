<?php

namespace WPML\Core\Port\Persistence;

interface OptionsInterface {


  public function get( string $optionName, $defaultValue = false );


  public function save( string $optionName, $value, $autoload = false );


  public function delete( string $optionName );


  public function add( string $optionName, $value, bool $autoload = true ): bool;


}
