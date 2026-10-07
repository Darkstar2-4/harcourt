<?php

namespace WPML\Infrastructure\WordPress\Port\Persistence;

use WPML\Core\Port\Persistence\OptionsInterface;

class Options implements OptionsInterface {


  public function get( string $optionName, $defaultValue = false ) {
    return \get_option( $optionName, $defaultValue );
  }


  public function save( string $optionName, $value, $autoload = false ) {
    \update_option( $optionName, $value, $autoload );
  }


  public function delete( string $optionName ) {
    \delete_option( $optionName );
  }


  public function add( string $optionName, $value, bool $autoload = true ): bool {
    return \add_option( $optionName, $value, '', $autoload ? 'yes' : 'no' );
  }


}
