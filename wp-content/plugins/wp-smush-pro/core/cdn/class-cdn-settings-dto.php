<?php
/**
 * CDN Settings DTO
 *
 * Handles conversion between PHP (snake_case/kebab-case) and React camelCase for CDN settings.
 *
 * @package Smush\Core\CDN
 * @since 3.25.0
 */

namespace Smush\Core\CDN;

use Smush\Core\Abstract_Settings_DTO;
use Smush\Core\Settings;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class CDN_Settings_DTO
 *
 * Converts CDN settings from snake_case/kebab-case to camelCase for React.
 *
 * @since 3.25.0
 */
class CDN_Settings_DTO extends Abstract_Settings_DTO {

	/**
	 * Top-level keys mapping.
	 * These are the main CDN settings from $cdn_fields in Settings class.
	 *
	 * @var array
	 */
	private static $top_level_keys = array(
		'cdn'               => 'cdn',
		'background_images' => 'backgroundImages',
		'cdn_dynamic_sizes' => 'cdnDynamicSizes',
		'webp'              => 'webp',  // NEXT_GEN_CDN_KEY
		'rest_api_support'  => 'restApiSupport',
		'excluded-keywords' => 'excludedKeywords',  // From cdn-advanced-settings
	);

	/**
	 * Keys that contain indexed arrays (lists of values) rather than nested settings objects.
	 * These arrays should be preserved as-is without recursive conversion.
	 *
	 * @var array
	 */
	private static $indexed_array_keys = array(
		'excluded-keywords',
		'excludedKeywords', // React version
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
	 * Sanitization schema for CDN settings (PHP keys, post-conversion).
	 * webp (NEXT_GEN_CDN_KEY) holds an integer mode; excluded-keywords is a deduplicated string list.
	 *
	 * @return array
	 */
	protected static function get_sanitization_schema() {
		return array(
			Settings::get_next_gen_cdn_key() => array( 'sanitizer' => 'intval' ),
			'excluded-keywords'              => array( 'sanitizer' => 'sanitize_text_field', 'nonempty_list' => true ),
		);
	}

	/**
	 * All remaining CDN settings are boolean toggles.
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
		// CDN settings are flat (no nesting), so always return top-level keys
		return self::$top_level_keys;
	}
}