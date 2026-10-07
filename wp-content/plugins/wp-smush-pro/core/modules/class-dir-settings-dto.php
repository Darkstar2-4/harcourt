<?php
/**
 * Directory Smush Settings DTO
 *
 * Handles conversion between PHP (snake_case) and React camelCase for directory smush settings.
 *
 * @package Smush\Core\Modules
 * @since 3.26.0
 */

namespace Smush\Core\Modules;

use Smush\Core\Abstract_Settings_DTO;
use Smush\Core\Settings;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Dir_Settings_DTO
 *
 * Converts directory smush settings from snake_case to camelCase for React.
 *
 * @since 3.26.0
 */
class Dir_Settings_DTO extends Abstract_Settings_DTO {

	/**
	 * Key map: PHP snake_case => React camelCase.
	 *
	 * @var array
	 */
	private static $top_level_keys = array(
		'dir_lossy'      => 'dirLossy',
		'dir_strip_exif' => 'dirStripExif',
	);

	/**
	 * No indexed-array values in directory settings.
	 *
	 * @return array
	 */
	protected static function get_indexed_array_keys() {
		return array();
	}

	/**
	 * Sanitization schema for directory settings.
	 *
	 * @return array
	 */
	protected static function get_sanitization_schema() {
		return array(
			'dir_lossy' => array( 'sanitizer' => 'intval' ),
		);
	}

	/**
	 * All remaining directory settings are boolean toggles.
	 *
	 * @return string
	 */
	protected static function get_fallback_sanitizer() {
		return 'wp_validate_boolean';
	}

	/**
	 * @param string|null $parent_key Unused.
	 *
	 * @return array
	 */
	protected static function get_key_map( $parent_key = null ) {
		return self::$top_level_keys;
	}

	/**
	 * Normalize incoming React props before converting to internal settings.
	 *
	 * @param array|null  $props      Incoming props (camelCase).
	 * @param string|null $parent_key Unused.
	 *
	 * @return array|null
	 */
	public static function from_react_props( $props, $parent_key = null ) {
		if ( isset( $props['dirLossy'] ) ) {
			$props['dirLossy'] = Settings::get_instance()->sanitize_lossy_level( $props['dirLossy'] );
		}

		return parent::from_react_props( $props, $parent_key );
	}

	/**
	 * Normalize internal settings before converting to React props.
	 *
	 * @param array|null  $settings   Settings array (snake_case).
	 * @param string|null $parent_key Unused.
	 *
	 * @return array|null
	 */
	public static function to_react_props( $settings, $parent_key = null ) {
		if ( isset( $settings['dir_lossy'] ) ) {
			$settings['dir_lossy'] = Settings::get_instance()->sanitize_lossy_level( $settings['dir_lossy'] );
		}

		return parent::to_react_props( $settings, $parent_key );
	}
}
