<?php

namespace Smush\Core\Threads;

/**
 * Thread-safe operations on a JSON object used as a typed record.
 *
 * The stored value is a flat JSON object whose field values are scalars
 * (bool, int, float, string) or nested arrays/objects. Supports atomic
 * field writes, reads, and integer arithmetic (increment/decrement).
 *
 * Usage:
 *   $rec = new JSON_Record( 'my_option_name' );
 *   $rec->set_values( [ 'count' => 0, 'active' => true ] );
 *   $rec->increment_values( [ 'count' ] );
 */
class JSON_Record extends JSON_Data {

	public function init() {
		return $this->initialize_json_object_option();
	}

	// -------------------------------------------------------------------------
	// Typed field write / read
	// -------------------------------------------------------------------------

	/**
	 * Atomically set one or more typed fields on the stored JSON object.
	 *
	 * Supported PHP types: bool, int, float, string, array, object.
	 *
	 * @param array $associative_array Map of field name → value.
	 *
	 * @return int|false
	 */
	public function set_values( $associative_array ) {
		return $this->run_json_set_query( $associative_array, function ( $value_column, $key, $value ) {
			global $wpdb;

			if ( is_bool( $value ) ) {
				$json_bool = $value ? 'TRUE' : 'FALSE';
				return $wpdb->prepare( "%s, {$json_bool}", "$.\"$key\"" );
			}

			if ( is_int( $value ) ) {
				return $wpdb->prepare( "%s, %d", "$.\"$key\"", $value );
			}

			if ( is_float( $value ) ) {
				return $wpdb->prepare( "%s, %f", "$.\"$key\"", $value );
			}

			if ( is_array( $value ) || is_object( $value ) ) {
				$prepared_json = $wpdb->prepare( '%s', wp_json_encode( $value ) );
				return $wpdb->prepare( "%s", "$.\"$key\"" ) . ", JSON_EXTRACT(CONCAT('[', {$prepared_json}, ']'), '$[0]')";
			}

			return $wpdb->prepare( "%s, %s", "$.\"$key\"", $value );
		} );
	}

	/**
	 * Read a single field from the stored JSON object.
	 *
	 * @param string $key
	 * @param mixed  $default
	 *
	 * @return mixed
	 */
	public function get_value( $key, $default = false ) {
		$values = $this->get();
		$values = empty( $values ) ? array() : $values;

		return isset( $values[ $key ] ) ? $values[ $key ] : $default;
	}

	// -------------------------------------------------------------------------
	// Integer field arithmetic
	// -------------------------------------------------------------------------

	/**
	 * Atomically increment a single field by 1 and return the new value.
	 *
	 * Uses MySQL's LAST_INSERT_ID(expr) trick so the increment and the read
	 * happen inside a single UPDATE statement — no separate SELECT needed and
	 * no two concurrent callers can claim the same value.
	 *
	 * Returns the new (post-increment) integer value, or false on DB error /
	 * when the row does not exist (auto_initialize = false and row missing).
	 *
	 * @param string $key Field name.
	 *
	 * @return int|false
	 */
	public function increment_and_get( $key ) {
		global $wpdb;

		list( $table, $column, $value_column ) = $this->get_table_columns();

		$this->maybe_init();

		$path = "$.\"$key\"";

		$rows = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET {$value_column} = JSON_SET(
			     {$value_column},
			     %s,
			     LAST_INSERT_ID(
			         CAST(JSON_UNQUOTE(IFNULL(JSON_EXTRACT({$value_column}, %s), -1)) + 1 AS UNSIGNED)
			     )
			 )
			 WHERE {$column} = %s",
			$path,
			$path,
			$this->option_id
		) );

		if ( ! $rows ) {
			return false;
		}

		return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
	}

	/**
	 * Increment each listed field by 1.
	 *
	 * @param string[] $keys
	 *
	 * @return int|false
	 */
	public function increment_values( $keys ) {
		$values = array_fill_keys( $keys, 1 );
		return $this->add_to_values( $values );
	}

	/**
	 * Add an arbitrary integer to each listed field.
	 *
	 * @param array $values Map of field name → addend (int).
	 *
	 * @return int|false
	 */
	public function add_to_values( $values ) {
		return $this->run_json_set_query( $values, function ( $value_column, $key, $addend ) {
			global $wpdb;

			return $wpdb->prepare(
				"%s, CAST(JSON_UNQUOTE(IFNULL(JSON_EXTRACT($value_column, %s), 0)) + %d AS SIGNED)",
				"$.\"$key\"",
				"$.\"$key\"",
				$addend
			);
		} );
	}

	/**
	 * Decrement each listed field by 1.
	 *
	 * @param string[] $keys
	 *
	 * @return int|false
	 */
	public function decrement_values( $keys ) {
		$values = array_fill_keys( $keys, 1 );
		return $this->subtract_from_values( $values );
	}

	/**
	 * Subtract an arbitrary integer from each listed field.
	 *
	 * @param array $values Map of field name → subtrahend (int).
	 *
	 * @return int|false
	 */
	public function subtract_from_values( $values ) {
		return $this->run_json_set_query( $values, function ( $value_column, $key, $subtrahend ) {
			global $wpdb;

			return $wpdb->prepare(
				"%s, CAST(JSON_UNQUOTE(IFNULL(JSON_EXTRACT($value_column, %s), 0)) - %d AS SIGNED)",
				"$.\"$key\"",
				"$.\"$key\"",
				$subtrahend
			);
		} );
	}

	// -------------------------------------------------------------------------
	// Private
	// -------------------------------------------------------------------------

	private function run_json_set_query( $values, $prepare_single_value_query ) {
		global $wpdb;

		list( $table, $column, $value_column ) = $this->get_table_columns();

		$this->maybe_init();

		$set_values = [];
		foreach ( $values as $key => $value ) {
			$set_values[] = call_user_func( $prepare_single_value_query, $value_column, $key, $value );
		}
		$set = implode( ', ', $set_values );

		$query = "
			UPDATE {$table}
			SET $value_column = JSON_SET($value_column, $set)
			WHERE {$column} = %s;
		";

		return $wpdb->query( $wpdb->prepare( $query, $this->option_id ) );
	}
}
