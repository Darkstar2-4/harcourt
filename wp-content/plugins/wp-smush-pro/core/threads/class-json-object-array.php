<?php

namespace Smush\Core\Threads;

/**
 * Thread-safe operations on a JSON array of objects stored in a WP option.
 *
 * The stored value is always a JSON array whose elements are JSON objects
 * (associative arrays). Concurrent appends are safe via a single-statement
 * JSON_ARRAY_APPEND update. Bulk replacement is intentionally not concurrent-
 * write safe and should only be used from single-writer contexts.
 *
 * Usage:
 *   $arr = new JSON_Object_Array( 'my_option_name' );
 *   $arr->append( [ 'id' => 'n1', 'content' => 'Hello.' ] );
 *   $arr->replace( $trimmed_list );
 */
class JSON_Object_Array extends JSON_Data {

	/**
	 * Atomically append a single associative array (object) to the JSON array.
	 *
	 * Uses JSON_ARRAY_APPEND with JSON_EXTRACT(CONCAT('[', expr, ']'), '$[0]')
	 * instead of CAST(? AS JSON) for compatibility with MariaDB 10.2+.
	 *
	 * @param array $object Associative array to append.
	 *
	 * @return int|false Affected rows or false on error.
	 */
	public function append( $object ) {
		global $wpdb;

		list( $table, $column, $value_column ) = $this->get_table_columns();

		$this->maybe_init();

		$json_string   = wp_json_encode( $object );
		$prepared_json = $wpdb->prepare( '%s', $json_string );
		$prepared_id   = $wpdb->prepare( '%s', $this->option_id );

		return $wpdb->query(
			"UPDATE {$table}
			 SET {$value_column} = JSON_ARRAY_APPEND({$value_column}, '$', JSON_EXTRACT(CONCAT('[', {$prepared_json}, ']'), '$[0]'))
			 WHERE {$column} = {$prepared_id}"
		);
	}

	/**
	 * Overwrite the entire stored JSON array with the supplied objects.
	 *
	 * NOT concurrent-write safe. Intended for single-writer contexts such as
	 * trimming an oversized list.
	 *
	 * Non-sequential PHP array keys are re-indexed (array_values).
	 *
	 * @param array $objects Indexed array of associative arrays to store.
	 *
	 * @return int|false Affected rows or false on DB error.
	 */
	public function unsafe_replace( $objects ) {
		return $this->unsafe_set( array_values( $objects ) );
	}

	/**
	 * Read a single object from the array by zero-based index.
	 *
	 * Uses a single JSON_EXTRACT call — no PHP-side read of the full array.
	 * Returns null when the index is out of bounds or the row does not exist.
	 *
	 * @param int $index Zero-based position in the array.
	 *
	 * @return array|null The decoded object, or null if not found.
	 */
	public function get_item( $index ) {
		global $wpdb;

		list( $table, $column, $value_column ) = $this->get_table_columns();

		$prepared_id   = $wpdb->prepare( '%s', $this->option_id );
		$prepared_path = $wpdb->prepare( '%s', '$[' . (int) $index . ']' );

		$json = $wpdb->get_var(
			"SELECT JSON_EXTRACT({$value_column}, {$prepared_path})
			 FROM {$table}
			 WHERE {$column} = {$prepared_id}
			 LIMIT 1"
		);

		if ( is_null( $json ) ) {
			return null;
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	public function init() {
		return $this->initialize_json_array_option();
	}
}