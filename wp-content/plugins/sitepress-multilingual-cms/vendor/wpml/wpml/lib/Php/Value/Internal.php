<?php

namespace WPML\PHP\Value;

use WPML\PHP\Exception\InvalidArgumentException;

abstract class Internal {
  const THROW_EXCEPTION = '_THROW_EXCEPTION_';
  const KEY_DOES_NOT_EXIST = '_KEY_DOES_NOT_EXIST_';


  public static function fallbackOrException( $fallback, $exceptionMsg ) {
    if ( $fallback === Internal::THROW_EXCEPTION ) {
      throw new InvalidArgumentException( $exceptionMsg );
    }

    return $fallback;
  }


  public static function getValueFromArray( $value ) {
    if ( ! is_array( $value ) ) {
      return $value;
    }

    if ( count( $value ) != 2 || ! isset( $value[0] ) || ! is_array( $value[0] ) || ! isset( $value[1] ) ) {
      return $value;
    }

    $array = $value[0];
    $key = $value[1];

    if ( ! isset( $array[$key] ) ) {
      return Internal::KEY_DOES_NOT_EXIST;
    }

    return $array[ $key ];
  }


  public static function msgKeyDoesNotExist( $value ) {
    if ( ! is_array( $value ) ) {
      return 'Value is not an array.';
    }

    if ( isset( $value[1] ) ) {
      return "Key '{$value[1]}' does not exist in the array.";
    }

    return 'No key provided.';
  }


}
