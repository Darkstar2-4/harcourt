<?php
/**
 * Next-Gen Settings DTO
 *
 * Handles conversion between PHP (snake_case/kebab-case) and React camelCase for next-gen settings.
 *
 * @package Smush\Core\Next_Gen
 * @since 3.25.0
 */

namespace Smush\Core\Next_Gen;

use Smush\Core\Abstract_Settings_DTO;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Next_Gen_Settings_DTO
 *
 * Converts next-gen settings (WebP and AVIF) from snake_case to camelCase for React.
 *
 * @since 3.25.0
 */
class Next_Gen_Settings_DTO extends Abstract_Settings_DTO {

	/**
	 * Top-level keys mapping.
	 * These are the WebP and AVIF settings.
	 *
	 * @var array
	 */
	private static $top_level_keys = array(
		// WebP fields
		'webp_mod'               => 'webpMod',
		'webp_direct_conversion' => 'webpDirectConversion',
		'webp_fallback'          => 'webpFallback',
		// AVIF fields
		'avif_mod'               => 'avifMod',
		'avif_fallback'          => 'avifFallback',
		'show_setup_wizard'      => 'showSetupWizard',
	);

	/**
	 * Keys that contain indexed arrays (lists of values) rather than nested settings objects.
	 * Currently, next-gen settings don't have indexed arrays, but this is here for consistency.
	 *
	 * @var array
	 */
	private static $indexed_array_keys = array();

	/**
	 * Get the list of keys that contain indexed arrays.
	 *
	 * @return array List of keys containing indexed arrays.
	 */
	protected static function get_indexed_array_keys() {
		return self::$indexed_array_keys;
	}

	/**
	 * All next-gen settings are boolean toggles.
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
		// Next-gen settings are flat (no nesting), so always return top-level keys
		return self::$top_level_keys;
	}
}