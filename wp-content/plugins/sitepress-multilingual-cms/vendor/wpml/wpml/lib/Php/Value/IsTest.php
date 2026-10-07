<?php

namespace WPML\PHP\Value;

use WPML\PHPUnit\TestCase;

class IsTest extends TestCase {


  public function testValidString( $input ) {
    $this->assertTrue( Is::string( $input ) );
  }


  public function dataValidString() {
    return [
      'string' => [ 'test string' ],
      'empty'  => [ '' ],
    ];
  }


  public function testInvalidString( $input ) {
    $this->assertFalse( Is::string( $input ) );
  }


  public function dataInvalidString() {
    return [
      'integer' => [ 123 ],
      'float'   => [ 1.23 ],
      'boolean' => [ true ],
      'array'   => [ [] ],
      'object'  => [ new \stdClass() ],
      'null'    => [ null ],
    ];
  }


  public function testStringInArray() {
    $array = [ 'title' => 'test string' ];
    $this->assertTrue( Is::string( [ $array, 'title' ] ) );

    $this->assertFalse( Is::string( [ $array, 'key-does-not-exist' ] ) );
  }


  public function testIsArrayOfSameTypeValidData( $input, $callback ) {
    $this->assertTrue( Is::arrayOfSameType( $input, $callback ) );
  }


  public function dataIsArrayOfSameTypeValidData() {
    return [
      'empty array' => [ [], 'is_int' ],
      'array of integers' => [ [ 1, 2, 3 ], 'is_int' ],
      'array of strings' => [ [ 'a', 'b', 'c' ], 'is_string' ],
      'array of arrays' => [ [ [], [], [] ], 'is_array' ],
    ];
  }


  public function testIsArrayOfSameTypeInvalidData( $input, $callback ) {
    $this->assertFalse( Is::arrayOfSameType( $input, $callback ) );
  }


  public function dataIsArrayOfSameTypeInvalidData() {
    return [
      'integer' => [ [ 1, 'a', 2 ], 'is_int' ],
      'float' => [ 1, 'is_int' ],
      'boolean' => [ [ true ], 'is_int' ],
      'array' => [ [ [] ], 'is_int' ],
      'object' => [ [ new \stdClass() ], 'is_int'],
      'array of integers with string' => [
        [ 1, '2', 3 ],
        [ Is::class, 'int' ],
      ],
    ];
  }


  public function testIsArray() {
    $array = [ 'a' => 1, 2 => 'b', 'c' => [] ];
    $this->assertTrue(
      Is::array(
        $array,
        [
          'a' => 'is_int',
          2 => 'is_string',
          '?c' => 'is_array',
          '?d' => 'is_string'
        ]
      )
    );
  }


  public function testIsInt( $input ) {
    $this->assertTrue( Is::int( $input ) );
  }


  public function dataIsInt() {
    return [
      'int' => [ 123 ],
      'zero' => [ 0 ],
    ];
  }


  public function testInvalidInt( $input ) {
    $this->assertFalse( Is::int( $input ) );
  }


  public function dataInvalidInt() {
    return [
      'float' => [ 1.23 ],
      'boolean' => [ true ],
      'array' => [ [] ],
      'object' => [ new \stdClass() ],
      'null' => [ null ],
    ];
  }


  public function testIntInArray() {
    $array = [ 'no' => 123 ];
    $this->assertTrue( Is::int( [ $array, 'no' ] ) );

    $this->assertFalse( Is::int( [ $array, 'key-does-not-exist' ] ) );
  }


}
