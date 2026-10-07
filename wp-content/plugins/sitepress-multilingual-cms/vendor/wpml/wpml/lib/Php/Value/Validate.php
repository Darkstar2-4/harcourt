<?php

namespace WPML\PHP\Value;

use WPML\PHP\Exception\InvalidArgumentException;


class Validate {


  public static function string( $value, $fallback = Internal::THROW_EXCEPTION ) {
    $valueToCheck = Internal::getValueFromArray( $value );

    if ( $valueToCheck === Internal::KEY_DOES_NOT_EXIST ) {
      return Internal::fallbackOrException( $fallback, Internal::msgKeyDoesNotExist( $value ) );
    }

    if ( ! is_string( $valueToCheck ) ) {
      return Internal::fallbackOrException( $fallback, "Value is not a string." );
    }

    return $valueToCheck;
  }


  public static function nonEmptyString( $value, $fallback = Internal::THROW_EXCEPTION ) {
    $valueOfArray = Internal::getValueFromArray( $value );

    if ( $valueOfArray === Internal::KEY_DOES_NOT_EXIST ) {
      return Internal::fallbackOrException( $fallback, Internal::msgKeyDoesNotExist( $value ) );
    }

    if ( ! is_string( $valueOfArray ) || trim( $valueOfArray ) === '' ) {
      return Internal::fallbackOrException( $fallback, "Value is not a non-empty string." );
    }

    return $valueOfArray;
  }


  public static function int( $value, $fallback = Internal::THROW_EXCEPTION ) {
    $value = Internal::getValueFromArray( $value );

    if ( ! is_numeric( $value ) ) {
      return Internal::fallbackOrException( $fallback, "Value is not an integer." );
    }

    $intValue = (int) $value;

    if ( $intValue != $value ) {
      return Internal::fallbackOrException( $fallback, "Value is not an integer." );
    }

    return $intValue;
  }


  public static function array( $value, $structure, $fallback = Internal::THROW_EXCEPTION ) {
    $value = Internal::getValueFromArray( $value );

    if ( ! is_array( $value ) ) {
      return Internal::fallbackOrException( $fallback, "Value is not an array." );
    }

    $array = [];
    foreach ( $structure as $key => $validateType ) {
      if (
        substr( $key, 0, 1 ) === '?'
        && ! isset( $value[ substr( $key, 1 ) ] )
      ) {
        continue;
      } elseif ( substr( $key, 0, 1 ) === '?' ) {
        $key = substr( $key, 1 );
      }

      if ( ! isset( $value[ $key ] ) || ! $validateType( $value[ $key ] ) ) {
        return Internal::fallbackOrException( $fallback, "Value is not an array with the correct structure." );
      }

      $array[ $key ] = $value[ $key ];
    }

    return $value;
  }


  public static function arrayOfSameType( $value, $validateType, $fallback = Internal::THROW_EXCEPTION ) {
    $value = Internal::getValueFromArray( $value );

    if ( ! is_array( $value ) ) {
      return Internal::fallbackOrException( $fallback, "Value is not an array." );
    }

    $arrayOfSameType = [];

    foreach ( $value as $item ) {
      $result = $validateType( $item );
      if ( $result === false ) {
        return Internal::fallbackOrException( $fallback, "Value is not an array of the same type." );
      }
      $arrayOfSameType[] = is_bool( $result ) ? $item : $result;
    }

    return $arrayOfSameType;
  }


}
