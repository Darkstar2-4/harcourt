<?php


namespace WPML\TM\Jobs;

class Utils {

	public static function insertUnderKeys( $keys, $array, $value ) {
		$array[ $keys[0] ] = count( $keys ) === 1
			? $value
			: self::insertUnderKeys(
				array_slice( $keys, 1 ),
				( isset( $array[ $keys[0] ] ) ? $array[ $keys[0] ] : [] ),
				$value
			);

		return $array;
	}

}
