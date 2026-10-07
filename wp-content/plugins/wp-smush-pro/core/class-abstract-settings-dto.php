<?php
/**
 * Abstract Settings DTO
 *
 * Base class for all settings DTOs that handle conversion between PHP and React.
 *
 * @package Smush\Core
 * @since 3.25.0
 */

namespace Smush\Core;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Abstract Class Abstract_Settings_DTO
 *
 * Provides common functionality for converting settings between PHP (snake_case/kebab-case)
 * and React (camelCase) formats.
 *
 * Child classes must implement:
 * - get_key_map() - Returns the appropriate key map based on context
 * - get_indexed_array_keys() - Returns list of keys that contain indexed arrays
 *
 * @since 3.25.0
 */
abstract class Abstract_Settings_DTO {

	/**
	 * Return keys whose array values have arbitrary, user-defined sub-keys
	 * that are not listed in the key map.
	 *
	 * @return string[]
	 */
	protected static function get_keys_with_dynamic_array_values() {
		return array();
	}

	/**
	 * Check whether a key's value contains dynamic (non-predefined) sub-keys.
	 *
	 * When true, sub-keys that are absent from the key map are passed through
	 * unchanged instead of being silently dropped.
	 *
	 * @param string $key The key to check.
	 *
	 * @return bool
	 */
	protected static function has_dynamic_array_value( $key ) {
		if ( empty( $key ) ) {
			return false;
		}

		return in_array( $key, static::get_keys_with_dynamic_array_values(), true );
	}

	/**
	 * Get the appropriate key map based on context.
	 * Child classes must implement this method.
	 *
	 * @param string $parent_key The parent key to determine which nested map to use.
	 *
	 * @return array The appropriate key map.
	 */
	abstract protected static function get_key_map( $parent_key = null );

	/**
	 * Get the sanitization schema for this DTO.
	 *
	 * Keys map to Settings_Sanitizer rule arrays (PHP keys, post-conversion).
	 * Child classes should override this to declare the type of each field.
	 * Any key not listed falls back to get_fallback_sanitizer().
	 *
	 * @return array Sanitization schema.
	 */
	protected static function get_sanitization_schema() {
		return array();
	}

	/**
	 * Get the fallback sanitizer callback for fields not covered by the schema.
	 * Defaults to sanitize_text_field; override for settings that are mostly booleans etc.
	 *
	 * @return callable Sanitizer callback.
	 */
	protected static function get_fallback_sanitizer() {
		return 'sanitize_text_field';
	}

	/**
	 * Get the list of keys that contain indexed arrays.
	 * Child classes must implement this method.
	 *
	 * @return array List of keys containing indexed arrays.
	 */
	abstract protected static function get_indexed_array_keys();

	/**
	 * Check if a key contains an indexed array of values rather than a nested settings object.
	 *
	 * @param string $key The key to check.
	 *
	 * @return bool True if the key contains an indexed array.
	 */
	protected static function is_indexed_array_key( $key ) {
		return in_array( $key, static::get_indexed_array_keys(), true );
	}

	/**
	 * Normalize data types from storage/transport.
	 *
	 * Converts string booleans ('true'/'false') to actual booleans, and numeric strings to numbers.
	 *
	 * @param mixed $data Data to normalize.
	 *
	 * @return mixed
	 */
	protected static function normalize_data_types( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				// Recursively normalize nested arrays.
				$data[ $key ] = self::normalize_data_types( $value );
			} elseif ( 'true' === $value ) {
				$data[ $key ] = true;
			} elseif ( 'false' === $value ) {
				$data[ $key ] = false;
			} elseif ( is_numeric( $value ) && is_string( $value ) && '' !== $value ) {
				// Convert numeric strings to proper integers/floats.
				$data[ $key ] = $value + 0;
			}
		}

		return $data;
	}

	/**
	 * Convert settings to React props.
	 *
	 * @param array  $settings   Settings array with PHP keys.
	 * @param string $parent_key The parent key for nested arrays (used to determine which key map to use).
	 *
	 * @return array Transformed settings with camelCase keys.
	 * @since 3.25.0
	 */
	public static function to_react_props( $settings, $parent_key = null ) {
		if ( empty( $settings ) || ! is_array( $settings ) ) {
			return array();
		}

		// Normalize data types first (convert string 'true'/'false' to bool, numeric strings to int).
		$settings = self::normalize_data_types( $settings );

		$react_props = array();
		$key_map     = static::get_key_map( $parent_key );

		$allow_identity_map = static::has_dynamic_array_value( $parent_key );
		foreach ( $settings as $key => $value ) {
			if ( isset( $key_map[ $key ] ) ) {
				$camel_key = $key_map[ $key ];
			} elseif ( $allow_identity_map ) {
				$camel_key = $key;
			} else {
				// Skip unmapped keys or log warning in debug mode.
				continue;
			}

			// Check if this key contains an indexed array (list of values) or a nested settings object.
			if ( is_array( $value ) && ! static::is_indexed_array_key( $key ) ) {
				// Recursively convert nested settings objects.
				$react_props[ $camel_key ] = static::to_react_props( $value, $key );
			} else {
				// Keep the value as-is (already normalized).
				$react_props[ $camel_key ] = $value;
			}
		}

		return $react_props;
	}

	/**
	 * Convert React props back to PHP settings format.
	 *
	 * @param array  $props      React props with camelCase keys.
	 * @param string $parent_key The parent key for nested arrays (used to determine which key map to use).
	 *
	 * @return array PHP settings with snake_case/kebab-case keys.
	 * @since 3.25.0
	 */
	public static function from_react_props( $props, $parent_key = null ) {
		if ( empty( $props ) || ! is_array( $props ) ) {
			return array();
		}

		// Normalize data types first (convert string 'true'/'false' to bool, numeric strings to int).
		$props = self::normalize_data_types( $props );

		$settings = array();
		$key_map  = static::get_key_map( $parent_key );

		// Flip the key map for reverse conversion.
		$reverse_map = array_flip( $key_map );

		$has_dynamic_array_value = static::has_dynamic_array_value( $parent_key );
		foreach ( $props as $key => $value ) {
			if ( isset( $reverse_map[ $key ] ) ) {
				$php_key = $reverse_map[ $key ];
			} elseif ( $has_dynamic_array_value ) {
				$php_key = $key;
			} else {
				// Skip unmapped keys or log warning in debug mode.
				continue;
			}

			// Check if this key contains an indexed array (list of values) or a nested settings object.
			if ( is_array( $value ) && ! static::is_indexed_array_key( $key ) ) {
				// Recursively convert nested settings objects.
				$settings[ $php_key ] = static::from_react_props( $value, $key );
			} else {
				// Keep the value as-is (already normalized).
				$settings[ $php_key ] = $value;
			}
		}

		// Sanitize at root level only (nested calls are sanitized as part of the parent schema).
		if ( null === $parent_key ) {
			$settings = Settings_Sanitizer::sanitize(
				$settings,
				static::get_sanitization_schema(),
				static::get_fallback_sanitizer()
			);
		}

		return $settings;
	}
}