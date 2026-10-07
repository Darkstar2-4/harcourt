<?php

namespace WPML\PHP\Value;

use WPML\PHP\Exception\InvalidArgumentException;


class Is {


  public static function string( $value ) {
    try {
      Validate::string( $value );
    } catch ( InvalidArgumentException $e ) {
      return false;
    }

    return true;
  }


  public static function nonEmptyString( $value ) {
    try {
      Validate::nonEmptyString( $value );
    } catch ( InvalidArgumentException $e ) {
      return false;
    }

    return true;
  }


  public static function int( $value ) {
    try {
      $value = Internal::getValueFromArray( $value );
    } catch ( InvalidArgumentException $e ) {
      return false;
    }

    return is_int( $value );
  }


  public static function arrayOfSameType( $value, $isType ) {
    try {
      Validate::arrayOfSameType( $value, $isType );
    } catch ( InvalidArgumentException $e ) {
      return false;
    }

    return true;
  }


  public static function array( $value, $structure ) {
    try {
      Validate::array( $value, $structure );
    } catch ( InvalidArgumentException $e ) {
      return false;
    }

    return true;
  }


}
