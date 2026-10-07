<?php
/**
 * Settings Sanitizer Utility
 *
 * Generic helpers to sanitize settings received from UI/API before persisting to the database.
 * Includes schema-based sanitization and small helpers for common shapes (e.g. non-empty string lists).
 *
 * @package Smush\Core
 */

namespace Smush\Core;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Settings_Sanitizer
 *
 * Provides schema-driven sanitization for settings arrays and helper methods for common settings shapes.
 */
class Settings_Sanitizer {

	/**
	 * Sanitize settings using a schema. Works for both partial updates and full settings arrays.
	 *
	 * Rules:
	 * - 'key' => 'callback' : sanitize that key. If value is array, apply callback to all leaves (map_deep).
	 * - 'key' => [ ... ]    : nested schema for arrays.
	 * - Keys missing from schema are sanitized with $fallback_callback (deep).
	 * - Nested keys missing from nested schema are also sanitized with $fallback_callback (deep).
	 *
	 * @param array           $input             Incoming settings (partial or full).
	 * @param array           $schema            Sanitization schema.
	 * @param string|callable $fallback_callback Fallback sanitizer applied via deep() when rule is missing/invalid.
	 *
	 * @return array Sanitized settings (same shape/keys as $input).
	 */
	public static function sanitize( $input, $schema = array(), $fallback_callback = 'sanitize_text_field' ) {
		$input  = is_array( $input ) ? $input : array();
		$schema = is_array( $schema ) ? $schema : array();

		$out = array();

		foreach ( $input as $key => $value ) {
			if ( array_key_exists( $key, $schema ) ) {
				$out[ $key ] = self::sanitize_value_by_rule( $value, $schema[ $key ], $fallback_callback );
				continue;
			}

			$out[ $key ] = self::deep( $value, $fallback_callback );
		}

		return $out;
	}

	/**
	 * Sanitize a value using a rule (callback or nested schema).
	 *
	 * @param mixed           $value             Value to sanitize.
	 * @param mixed           $rule              Rule describing how to sanitize the value.
	 * @param string|callable $fallback_callback  Fallback sanitizer applied via deep() when rule is missing/invalid.
	 *
	 * @return mixed
	 */
	private static function sanitize_value_by_rule( $value, $rule, $fallback_callback ) {
		/**
		 * Security note:
		 * Only allow executables via the explicit config-schema:
		 * array( 'sanitizer' => (callable) )
		 *
		 * This avoids treating arbitrary input arrays/strings as callables.
		 */

		if ( is_array( $rule ) && array_key_exists( 'sanitizer', $rule ) ) {
			$callback  = is_callable( $rule['sanitizer'] ) ? $rule['sanitizer'] : $fallback_callback;
			$sanitized = self::deep( $value, $callback );

			// Optionally normalise the whole list: trim, remove empties, deduplicate.
			if ( ! empty( $rule['nonempty_list'] ) ) {
				$sanitized = self::sanitize_nonempty_string_list( $sanitized );
			}

			return $sanitized;
		}

		// Nested schema (no callables allowed here).
		if ( is_array( $rule ) ) {
			$value = is_array( $value ) ? $value : array();
			$out   = array();

			foreach ( $value as $child_key => $child_value ) {
				if ( array_key_exists( $child_key, $rule ) ) {
					$out[ $child_key ] = self::sanitize_value_by_rule( $child_value, $rule[ $child_key ], $fallback_callback );
				} else {
					$out[ $child_key ] = self::deep( $child_value, $fallback_callback );
				}
			}

			return $out;
		}

		// Invalid rule => fallback.
		return self::deep( $value, $fallback_callback );
	}

	/**
	 * Sanitize a list of strings.
	 *
	 * Normalizes typical "list" inputs coming from UI components:
	 * - Ensures the result is always an array.
	 * - Trims each item.
	 * - Drops empty items.
	 * - Removes duplicates.
	 *
	 * @param mixed $sanitized_value Incoming value.
	 *
	 * @return array Normalized list of non-empty, unique strings.
	 */
	public static function sanitize_nonempty_string_list( $sanitized_value ) {
		if ( ! is_array( $sanitized_value ) ) {
			$sanitized_value = array();
		}

		$items = array();
		foreach ( $sanitized_value as $item ) {
			$item = is_string( $item ) ? trim( $item ) : '';
			if ( '' === $item ) {
				continue;
			}
			$items[] = $item;
		}

		$items = array_values( array_unique( $items ) );

		return $items;
	}

	/**
	 * Deep sanitizer: apply callback to all leaf values.
	 *
	 * @param mixed    $value    Value to sanitize (scalar|array|object).
	 * @param callable $callback Callback to apply to leaf values.
	 *
	 * @return mixed
	 */
	public static function deep( $value, $callback ) {
		if ( function_exists( 'map_deep' ) ) {
			return map_deep( $value, $callback );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::deep( $v, $callback );
			}
			return $value;
		}

		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $k => $v ) {
				$value->$k = self::deep( $v, $callback );
			}
			return $value;
		}

		return call_user_func( $callback, $value );
	}
}