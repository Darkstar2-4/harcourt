<?php

namespace Smush\Core\Threads;

/**
 * Thread-safe operations on a JSON array of scalar values stored in a WP option.
 *
 * The stored value is always a JSON array whose elements are scalars
 * (strings or integers). Concurrent appends are safe; each write
 * uses a single SQL statement with no PHP-side read-modify-write.
 *
 * Usage:
 *   $arr = new JSON_Scalar_Array( 'my_option_name' );
 *   $arr->append( [ 'value1', 'value2' ] );
 *   $arr->remove( 'value1' );
 */
class JSON_Scalar_Array extends JSON_Data {

	public function init() {
		return $this->initialize_json_array_option();
	}

	/**
	 * Atomically append one or more scalar values to the JSON array.
	 *
	 * @param array $values Indexed array of strings or integers.
	 *
	 * @return int|false Affected rows or false on error.
	 */
	public function append( $values ) {
		global $wpdb;

		list( $table, $column, $value_column ) = $this->get_table_columns();

		$this->maybe_init();

		$json_values = [];
		foreach ( $values as $value ) {
			$json_values[] = $wpdb->prepare( is_int( $value ) ? "'$', %d" : "'$', %s", $value );
		}
		$json_values_string = implode( ',', $json_values );
		$option_id          = $this->option_id;

		return $wpdb->query( "
			UPDATE {$table}
			SET {$value_column} = JSON_ARRAY_APPEND({$value_column}, {$json_values_string})
			WHERE {$column} = '$option_id';
		" );
	}

	/**
	 * Atomically remove the first occurrence of $value from the JSON array.
	 * No-op if the value is not present.
	 *
	 * @param string|int $value
	 *
	 * @return int|false Affected rows or false on error.
	 */
	public function remove( $value ) {
		global $wpdb;

		list( $table, $column, $value_column ) = $this->get_table_columns();

		$this->maybe_init();

		$json_value = $wpdb->prepare( is_int( $value ) ? "%d" : "%s", $value );
		$option_id  = $this->option_id;

		return $wpdb->query( "
			UPDATE {$table}
			SET {$value_column} = IF(
			    JSON_SEARCH({$value_column}, 'one', {$json_value}, NULL, '$') IS NOT NULL,
			    JSON_REMOVE({$value_column}, JSON_UNQUOTE(JSON_SEARCH({$value_column}, 'one', {$json_value}, NULL, '$'))),
			    {$value_column}
			)
			WHERE {$column} = '$option_id';
		" );
	}
}
