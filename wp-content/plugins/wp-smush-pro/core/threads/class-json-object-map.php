<?php

namespace Smush\Core\Threads;

/**
 * Thread-safe operations on a JSON object whose values are themselves JSON objects.
 *
 * The stored value is a JSON object (map) where each key maps to a nested
 * JSON object (associative array). Use this when the top-level option behaves
 * like a keyed collection of sub-records.
 *
 * Usage:
 *   $map = new JSON_Object_Map( 'my_option_name' );
 *   $map->add( 'site_123', [ 'status' => 'active', 'count' => 0 ] );
 *   $map->remove( 'site_123' );
 */
class JSON_Object_Map extends JSON_Data {

	public function init() {
		return $this->initialize_json_object_option();
	}

	/**
	 * Overwrite the entire stored JSON object.
	 *
	 * Casts $value to object before encoding so that an empty PHP array is
	 * stored as '{}' rather than '[]', keeping the row a valid JSON object.
	 *
	 * @param array|object $value
	 *
	 * @return int|false
	 */
	public function unsafe_set( $value ) {
		return parent::unsafe_set( (object) $value );
	}

	/**
	 * Set a key whose value is a JSON object built from $data.
	 *
	 * @param string $key  Top-level key inside the stored JSON object.
	 * @param array  $data Associative array to store under $key.
	 *
	 * @return int|false
	 */
	public function add( $key, $data ) {
		global $wpdb;

		list( $table, $column, $value_column ) = $this->get_table_columns();

		$this->maybe_init();

		$json_object = [];
		foreach ( $data as $data_key => $value ) {
			$json_object[] = $wpdb->prepare( is_int( $value ) ? "%s, %d" : "%s, %s", $data_key, $value );
		}
		$json_object_string = implode( ',', $json_object );
		$option_id          = $this->option_id;

		return $wpdb->query( "
			UPDATE {$table}
			SET {$value_column} = JSON_SET({$value_column}, '$.\"$key\"', JSON_OBJECT({$json_object_string}))
			WHERE {$column} = '$option_id';
		" );
	}

	/**
	 * Remove a top-level key from the stored JSON object.
	 *
	 * @param string $key
	 *
	 * @return int|false
	 */
	public function remove( $key ) {
		global $wpdb;

		list( $table, $column, $value_column ) = $this->get_table_columns();

		$this->maybe_init();
		$option_id = $this->option_id;

		return $wpdb->query( "
			UPDATE {$table}
			SET {$value_column} = JSON_REMOVE({$value_column}, '$.\"$key\"')
			WHERE {$column} = '$option_id';
		" );
	}
}