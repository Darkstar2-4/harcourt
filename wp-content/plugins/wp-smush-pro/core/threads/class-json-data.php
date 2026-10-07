<?php

namespace Smush\Core\Threads;

/**
 * Base class for thread-safe JSON option storage.
 *
 * Subclasses target a specific JSON data structure (object, scalar array, object array).
 * The option identity and storage scope are fixed at construction time.
 */
abstract class JSON_Data {

	/** @var string WP option name. */
	protected $option_id;

	/** @var bool When true, data lives in wp_sitemeta (multisite); otherwise wp_options. */
	protected $site_option;

	/**
	 * @var bool When true, the underlying JSON structure is created automatically before
	 *           every write operation. When false, the caller must invoke init() explicitly
	 *           before any writes; if the row does not exist the operation returns 0/false,
	 *           allowing callers to detect a concurrent delete and abort.
	 */
	protected $auto_initialize;

	/**
	 * @param string $option_id       WP option name.
	 * @param bool   $auto_initialize Auto-create the JSON structure before writes. Default true.
	 */
	public function __construct( $option_id, $auto_initialize = true ) {
		$this->option_id      = $option_id;
		$this->auto_initialize = $auto_initialize;
	}

	/**
	 * Explicitly initialize the underlying JSON structure.
	 *
	 * Must be called before any write operation when auto_initialize is false.
	 * Safe to call multiple times — uses INSERT … ON DUPLICATE KEY UPDATE.
	 *
	 * @return int|false
	 */
	abstract public function init();

	/**
	 * Call init() only when auto_initialize is enabled.
	 * Used internally by write operations.
	 */
	protected function maybe_init() {
		if ( $this->auto_initialize ) {
			$this->init();
		}
	}

	/**
	 * Delete the option row entirely.
	 *
	 * @return int|false Affected rows or false on error.
	 */
	public function delete() {
		global $wpdb;

		list( $table, $column ) = $this->get_table_columns();

		return $wpdb->delete( $table, array( $column => $this->option_id ), '%s' );
	}

	/**
	 * Read the stored value.
	 *
	 * @param mixed $default Returned when the option is missing or non-JSON.
	 *
	 * @return mixed
	 */
	public function get( $default = false ) {
		return $this->get_value_from_db( $default );
	}

	/**
	 * Overwrite the entire stored value with a JSON blob.
	 *
	 * NOT concurrent-write safe. Two concurrent callers will race and one write
	 * will be lost. Use field-level methods (set_values, increment_values, etc.)
	 * for concurrent-write-safe updates.
	 *
	 * @param mixed $value Must be JSON-serialisable.
	 *
	 * @return int|false Affected rows or false on error.
	 */
	public function unsafe_set( $value ) {
		return $this->set_value_in_db( $value );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	protected function get_value_from_db( $default ) {
		global $wpdb;

		list( $table, $column, $value_column, $key_column ) = $this->get_table_columns();

		$row = $wpdb->get_row( $wpdb->prepare( "
			SELECT *
			FROM {$table}
			WHERE {$column} = %s
			ORDER BY {$key_column} ASC
			LIMIT 1
		", $this->option_id ) );

		if ( empty( $row->$value_column ) || ! is_object( $row ) ) {
			return $default;
		}

		$decoded = json_decode( $row->$value_column, true );
		if ( is_null( $decoded ) ) {
			return $default;
		}

		return $decoded;
	}

	protected function set_value_in_db( $value ) {
		global $wpdb;

		list( $table, $column, $value_column ) = $this->get_table_columns();

		$json           = wp_json_encode( $value );
		$prepared_id    = $wpdb->prepare( '%s', $this->option_id );
		$prepared_value = $wpdb->prepare( '%s', $json );

		return $wpdb->query(
			"INSERT INTO {$table} (`{$column}`, `{$value_column}`)
			 VALUES ({$prepared_id}, {$prepared_value})
			 ON DUPLICATE KEY UPDATE `{$value_column}` = {$prepared_value}"
		);
	}

	/**
	 * Returns [ $table, $column, $value_column, $key_column ].
	 *
	 * @return array
	 */
	protected function get_table_columns() {
		global $wpdb;

		$table        = $wpdb->options;
		$column       = 'option_name';
		$value_column = 'option_value';
		$key_column   = 'option_id';

		return array( $table, $column, $value_column, $key_column );
	}

	protected function initialize_json_array_option() {
		global $wpdb;

		list( $table, $column, $value_column ) = $this->get_table_columns();

		return $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (`{$column}`, `{$value_column}`)
			 VALUES (%s, '[]')
			 ON DUPLICATE KEY UPDATE
			     `{$value_column}` = IF(JSON_VALID(`{$value_column}`), `{$value_column}`, '[]')",
			$this->option_id
		) );
	}

	protected function initialize_json_object_option() {
		global $wpdb;

		list( $table, $column, $value_column ) = $this->get_table_columns();

		return $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (`{$column}`, `{$value_column}`)
			 VALUES (%s, '{}')
			 ON DUPLICATE KEY UPDATE
			     `{$value_column}` = IF(JSON_VALID(`{$value_column}`), `{$value_column}`, '{}')",
			$this->option_id
		) );
	}


}
