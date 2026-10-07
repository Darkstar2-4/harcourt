<?php
/**
 * Settings Data Transfer Object
 *
 * Handles conversion between PHP (snake_case) and React camelCase for main settings.
 *
 * Note: Specialized settings are handled by their own DTOs:
 * - CDN settings: CDN_Settings_DTO
 * - Lazy Load settings: Lazy_Load_Settings_DTO
 * - Next-Gen settings: Next_Gen_Settings_DTO
 * - Preload settings: Preload_Settings_DTO
 *
 * @package Smush\Core
 * @since 3.25.0
 */

namespace Smush\Core;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Settings_DTO
 *
 * Converts main settings from snake_case to camelCase for React.
 *
 * @since 3.25.0
 */
class Settings_DTO extends Abstract_Settings_DTO {

	/**
	 * Top-level keys mapping.
	 * These are the main settings that aren't handled by specialized DTOs.
	 *
	 * @var array
	 */
	private static $top_level_keys = array(
		'auto'              => 'auto',
		'lossy'             => 'lossy',
		'strip_exif'        => 'stripExif',
		'resize'            => 'resize',
		'original'          => 'original',
		'backup'            => 'backup',
		'no_scale'          => 'noScale',
		'png_to_jpg'        => 'pngToJpg',
		'nextgen'           => 'nextgen',
		's3'                => 's3',
		'gutenberg'         => 'gutenberg',
		'js_builder'        => 'jsBuilder',
		'gform'             => 'gform',
		'auto_resizing'     => 'autoResizing',
		'usage'             => 'usage',
		'accessible_colors' => 'accessibleColors',
		'keep_data'         => 'keepData',
		'lazy_load'         => 'lazyLoad',
		'background_email'  => 'backgroundEmail',
		'disable_streams'   => 'disableStreams',
		'image_dimensions'  => 'imageDimensions',
		'image_sizes'       => 'imageSizes',
		'resize_sizes'      => 'resizeSizes',
	);

	/**
	 * Keys that contain indexed arrays (lists of values) rather than nested settings objects.
	 *
	 * @var array
	 */
	private static $indexed_array_keys = array(
		'image_sizes',
		'imageSizes',
		'resize_sizes',
		'resizeSizes',
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
	 * Sanitization schema for general settings (PHP keys, post-conversion).
	 *
	 * @return array
	 */
	protected static function get_sanitization_schema() {
		return array(
			'lossy'        => array( 'sanitizer' => 'intval' ),
			'image_sizes'  => array( 'sanitizer' => 'sanitize_text_field' ),
			'resize_sizes' => array( 'sanitizer' => 'intval' ),
		);
	}

	/**
	 * Most general settings are boolean toggles; use wp_validate_boolean as the fallback.
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
		// Main settings are flat (no nesting), so always return top-level keys.
		return self::$top_level_keys;
	}

	/**
	 * Normalize incoming React props before converting to internal settings.
	 *
	 * @param array|null  $props       Incoming props (camelCase).
	 * @param string|null $parent_key Parent key (unused for main settings).
	 *
	 * @return array|null Normalized props.
	 */
	public static function from_react_props( $props, $parent_key = null ) {
		if ( isset( $props['lossy'] ) ) {
			$props['lossy'] = Settings::get_instance()->sanitize_lossy_level( $props['lossy'] );
		}

		return parent::from_react_props( $props, $parent_key );
	}

	/**
	 * Normalize internal settings before converting to React props.
	 *
	 * @param array|null  $settings   Settings array (snake_case).
	 * @param string|null $parent_key Parent key (unused for main settings).
	 *
	 * @return array|null Normalized settings.
	 */
	public static function to_react_props( $settings, $parent_key = null ) {
		if ( isset( $settings['lossy'] ) ) {
			$settings['lossy'] = Settings::get_instance()->sanitize_lossy_level( $settings['lossy'] );
		}

		return parent::to_react_props( $settings, $parent_key );
	}
}