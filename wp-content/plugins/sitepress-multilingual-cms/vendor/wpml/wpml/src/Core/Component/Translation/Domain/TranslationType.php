<?php

namespace WPML\Core\Component\Translation\Domain;

use WPML\PHP\Exception\InvalidArgumentException;

class TranslationType {
  const POST = 'post';
  const PACKAGE = 'package';
  const STRING_BATCH = 'string-batch';
  const STRING = 'string';

  private $value;


  public function __construct( string $value ) {
    if ( in_array( $value, self::getAll() ) ) {
      $this->value = $value;
    } else {
      throw new InvalidArgumentException( 'Invalid job type: ' . $value );
    }

  }


  public function get(): string {
    return $this->value;
  }


  public static function getAll(): array {
    return [
      self::POST,
      self::PACKAGE,
      self::STRING_BATCH,
      self::STRING,
    ];
  }


  public static function post(): self {
    return new self( self::POST );
  }


  public static function package(): self {
    return new self( self::PACKAGE );
  }


  public static function stringBatch(): self {
    return new self( self::STRING_BATCH );
  }


  public static function string(): self {
    return new self( self::STRING );
  }


}
