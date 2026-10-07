<?php
/**
 * Preload Settings DTO
 *
 * Handles conversion between PHP (snake_case) and React camelCase for preload settings.
 *
 * @package Smush\Core\LCP
 * @since 3.25.0
 */

namespace Smush\Core\LCP;

use Smush\Core\Abstract_Settings_DTO;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Preload_Settings_DTO
 *
 * Converts preload settings from snake_case to camelCase for React.
 *
 * @since 3.25.0
 */
class Preload_Settings_DTO extends Abstract_Settings_DTO {

	/**
	 * Top-level keys mapping.
	 * These are the preload settings from main settings and wp-smush-preload.
	 *
	 * @var array
	 */
	private static $top_level_keys = array(
		// Main settings field
		'preload_images'    => 'preloadImages',
		// wp-smush-preload option fields
		'lcp_fetchpriority' => 'lcpFetchpriority',
		'exclude-pages'     => 'excludePages',
	);

	/**
	 * Keys that contain indexed arrays (lists of values) rather than nested settings objects.
	 *
	 * @var array
	 */
	private static $indexed_array_keys = array(
		'exclude-pages',
		'excludePages', // React version
	);

	/**
	 * Get the list of keys that contain indexed arrays.
	 *
	 * @return array List of keys containing indexed arrays.
	 */
	protected static function get_indexed_array_keys() {
		return self::$indexed_array_keys;
	}

	/**
	 * Sanitization schema for preload settings (PHP keys, post-conversion).
	 *
	 * @return array
	 */
	protected static function get_sanitization_schema() {
		return array(
			'exclude-pages' => array( 'sanitizer' => 'sanitize_text_field', 'nonempty_list' => true ),
		);
	}

	/**
	 * Most preload settings are boolean toggles.
	 *
	 * @return string
	 */
	protected static function get_fallback_sanitizer() {
		return 'wp_validate_boolean';
	}

	/**
	 * Get the appropriate key map based on context.
	 *
	 * @param string $parent_key The parent key to determine which nested map to use.
	 *
	 * @return array The appropriate key map.
	 */
	protected static function get_key_map( $parent_key = null ) {
		// Preload settings are flat (no nesting), so always return top-level keys
		return self::$top_level_keys;
	}
}