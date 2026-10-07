<?php
/**
 * Process Status DTO
 *
 * Handles conversion between PHP snake_case and React camelCase for process status data.
 *
 * @package Smush\Core\Background
 * @since 3.25.0
 */

namespace Smush\Core\Background;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Process_Status_DTO
 *
 * Converts background process status data from snake_case to camelCase.
 * Used for both bulk smush and scan progress data.
 *
 * @since 3.25.0
 */
class Process_Status_DTO {

	/**
	 * Maps PHP keys to React prop names.
	 *
	 * @since 3.25.0
	 * @var array
	 */
	private static $status_map = array(
		'in_processing'   => 'inProcessing',
		'is_cancelled'    => 'isCancelled',
		'is_completed'    => 'isCompleted',
		'is_dead'         => 'isDead',
		'total_items'     => 'totalItems',
		'processed_items' => 'processedItems',
		'failed_items'    => 'failedItems',
		'is_paused'       => 'isPaused',
	);

	/**
	 * Convert process status data to React props.
	 *
	 * @param array $status_data Raw status from background process.
	 *
	 * @return array Transformed data for React.
	 * @since 3.25.0
	 *
	 */
	public static function to_react_props( $status_data ) {
		if ( empty( $status_data ) || ! is_array( $status_data ) ) {
			return array();
		}

		$react_props = array();

		foreach ( $status_data as $key => $value ) {
			$react_key = self::get_react_key( $key );
			$react_props[ $react_key ] = $value;
		}

		return $react_props;
	}

	/**
	 * Get React prop name for a PHP key.
	 *
	 * @param string $php_key PHP snake_case key.
	 *
	 * @return string React camelCase key.
	 * @since 3.25.0
	 *
	 */
	private static function get_react_key( $php_key ) {
		// If mapped explicitly, use the map
		if ( isset( self::$status_map[ $php_key ] ) ) {
			return self::$status_map[ $php_key ];
		}

		// Otherwise, return as-is (fallback for unmapped keys)
		return $php_key;
	}
}
