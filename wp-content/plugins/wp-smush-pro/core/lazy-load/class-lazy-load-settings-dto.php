<?php
/**
 * Lazy Load Settings DTO
 *
 * Handles conversion between PHP (snake_case/kebab-case) and React camelCase for lazy load settings.
 *
 * @package Smush\Core\Lazy_Load
 * @since 3.25.0
 */

namespace Smush\Core\Lazy_Load;

use Smush\Core\Abstract_Settings_DTO;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Lazy_Load_Settings_DTO
 *
 * Converts lazy load settings from snake_case/kebab-case to camelCase for React.
 *
 * @since 3.25.0
 */
class Lazy_Load_Settings_DTO extends Abstract_Settings_DTO {

	/**
	 * Parent keys whose nested keys should be preserved as-is when unmapped.
	 *
	 * @return string[]
	 */
	protected static function get_keys_with_dynamic_array_values() {
		return array( 'include' );
	}

	/**
	 * Top-level keys mapping.
	 *
	 * @var array
	 */
	private static $top_level_keys = array(
		'lazy_load'         => 'lazyLoad',
		'exclude-pages'     => 'excludePages',
		'exclude-classes'   => 'excludeClasses',
		'noscript_fallback' => 'noscriptFallback',
		'native'            => 'native',
		'footer'            => 'footer',
		'animation'         => 'animation',
		'format'            => 'format',
		'include'           => 'include',
		'output'            => 'output',
	);

	/**
	 * Animation object keys mapping.
	 * Used for keys directly inside the 'animation' object.
	 *
	 * @var array
	 */
	private static $animation_keys = array(
		'selected'    => 'selected',
		'fadein'      => 'fadein',
		'spinner'     => 'spinner',
		'placeholder' => 'placeholder',
	);

	/**
	 * Fadein nested keys mapping.
	 * Used for keys inside the 'animation.fadein' object.
	 *
	 * @var array
	 */
	private static $fadein_keys = array(
		'duration' => 'duration',
		'delay'    => 'delay',
	);

	/**
	 * Spinner nested keys mapping.
	 * Used for keys inside the 'animation.spinner' object.
	 *
	 * @var array
	 */
	private static $spinner_keys = array(
		'selected' => 'selected',
		'custom'   => 'custom',
	);

	/**
	 * Placeholder nested keys mapping.
	 * Used for keys inside the 'animation.placeholder' object.
	 *
	 * @var array
	 */
	private static $placeholder_keys = array(
		'selected' => 'selected',
		'custom'   => 'custom',
		'color'    => 'color',
	);

	/**
	 * Format nested keys mapping.
	 * Used for keys inside the 'format' object.
	 *
	 * @var array
	 */
	private static $format_keys = array(
		'embed_video' => 'embedVideo',
		'jpeg'        => 'jpeg',
		'jpg'         => 'jpg',
		'png'         => 'png',
		'gif'         => 'gif',
		'svg'         => 'svg',
		'webp'        => 'webp',
		'iframe'      => 'iframe',
	);

	/**
	 * Output nested keys mapping.
	 * Used for keys inside the 'output' object.
	 *
	 * @var array
	 */
	private static $output_keys = array(
		'content'    => 'content',
		'thumbnails' => 'thumbnails',
		'gravatars'  => 'gravatars',
		'widgets'    => 'widgets',
	);

	/**
	 * Keys that contain indexed arrays (lists of values) rather than nested settings objects.
	 * These arrays should be preserved as-is without recursive conversion.
	 *
	 * @var array
	 */
	private static $indexed_array_keys = array(
		'exclude-pages',
		'exclude-classes',
		'excludePages',    // React version.
		'excludeClasses',  // React version.
		'custom',          // Used in spinner and placeholder.
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
	 * Sanitization schema for lazy-load settings (PHP keys, post-conversion).
	 *
	 * @return array
	 */
	protected static function get_sanitization_schema() {
		return array(
			'lazy_load'         => array( 'sanitizer' => 'wp_validate_boolean' ),
			'format'            => array( 'sanitizer' => 'wp_validate_boolean' ),
			'output'            => array( 'sanitizer' => 'wp_validate_boolean' ),
			'include'           => array( 'sanitizer' => 'wp_validate_boolean' ),
			'exclude-pages'     => array( 'sanitizer' => 'sanitize_text_field', 'nonempty_list' => true ),
			'exclude-classes'   => array( 'sanitizer' => 'sanitize_text_field', 'nonempty_list' => true ),
			'footer'            => array( 'sanitizer' => 'wp_validate_boolean' ),
			'native'            => array( 'sanitizer' => 'wp_validate_boolean' ),
			'noscript_fallback' => array( 'sanitizer' => 'wp_validate_boolean' ),
			'animation'         => array(
				'selected'    => array( 'sanitizer' => 'sanitize_text_field' ),
				'fadein'      => array(
					'duration' => array( 'sanitizer' => 'intval' ),
					'delay'    => array( 'sanitizer' => 'intval' ),
				),
				'spinner'     => array(
					'selected' => array( 'sanitizer' => 'intval' ),
					'custom'   => array( 'sanitizer' => 'intval' ),
				),
				'placeholder' => array(
					'selected' => array( 'sanitizer' => 'intval' ),
					'custom'   => array( 'sanitizer' => 'intval' ),
					'color'    => array( 'sanitizer' => array( __CLASS__, 'sanitize_color' ) ),
				),
			),
		);
	}

	/**
	 * Sanitize color value to ensure it's a valid color format.
	 *
	 * Supports hex colors, rgb/rgba.
	 *
	 * @param string $color The color value to sanitize.
	 *
	 * @return string Sanitized color value or empty string if invalid.
	 */
	public static function sanitize_color( $color ) {
		if ( ! is_string( $color ) ) {
			return '';
		}

		// Remove any whitespace.
		$color = trim( $color );

		// Check if it's a valid hex color (#fff, #ffffff, #ffffffff) via WordPress core.
		$hex = sanitize_hex_color( $color );
		if ( null !== $hex ) {
			return $hex;
		}

		// Check for rgb/rgba colors.
		if ( preg_match( '/^rgba?\(\s*(25[0-5]|2[0-4]\d|1\d{2}|\d{1,2})\s*,\s*(25[0-5]|2[0-4]\d|1\d{2}|\d{1,2})\s*,\s*(25[0-5]|2[0-4]\d|1\d{2}|\d{1,2})\s*(?:,\s*(?:1(?:\.0+)?|0(?:\.\d+)?|\.\d+)\s*)?\)$/', $color ) ) {
			return $color;
		}

		return '';
	}

	/**
	 * Get the appropriate key map based on context.
	 *
	 * @param string $parent_key The parent key to determine which nested map to use.
	 *
	 * @return array The appropriate key map.
	 */
	protected static function get_key_map( $parent_key = null ) {
		if ( null === $parent_key ) {
			return self::$top_level_keys;
		}

		switch ( $parent_key ) {
			case 'animation':
				return self::$animation_keys;
			case 'fadein':
				return self::$fadein_keys;
			case 'spinner':
				return self::$spinner_keys;
			case 'placeholder':
				return self::$placeholder_keys;
			case 'format':
				return self::$format_keys;
			case 'output':
				return self::$output_keys;
			default:
				return array();
		}
	}

	/**
	 * Convert React props back to PHP settings format.
	 *
	 * @param array  $props      React props with camelCase keys.
	 * @param string $parent_key The parent key for nested arrays (used to determine which key map to use).
	 *
	 * @return array PHP settings with snake_case/kebab-case keys.
	 */
	public static function from_react_props( $props, $parent_key = null ) {
		if ( empty( $props ) || ! is_array( $props ) ) {
			return array();
		}

		// Allow DTOs to hydrate/reshape values for storage before normalizing types.
		$props = static::prepare_for_storage( $props, $parent_key );

		return parent::from_react_props( $props, $parent_key );
	}

	/**
	 * Prepare React props for storage.
	 *
	 * Converts UI-friendly attachment objects back into attachment ID arrays.
	 *
	 * @param array       $props      React props with camelCase keys.
	 * @param string|null $parent_key Parent key context.
	 *
	 * @return array
	 */
	protected static function prepare_for_storage( $props, $parent_key = null ) {
		if ( 'spinner' === $parent_key || 'placeholder' === $parent_key ) {
			if ( ! empty( $props['custom'] ) && is_array( $props['custom'] ) ) {
				$props['custom'] = self::normalize_attachment_ids( $props['custom'] );
			}
		}

		return $props;
	}

	/**
	 * Normalize an array of attachment IDs (or arrays containing an `id`) into a clean list of IDs.
	 *
	 * @param array $items Attachment IDs or arrays containing 'id'.
	 *
	 * @return int[]
	 */
	private static function normalize_attachment_ids( $items ) {
		if ( empty( $items ) || ! is_array( $items ) ) {
			return array();
		}

		$ids = array_map(
			function ( $attachment ) {
				if ( is_array( $attachment ) && isset( $attachment['id'] ) ) {
					return (int) $attachment['id'];
				}
				return is_numeric( $attachment ) ? (int) $attachment : 0;
			},
			$items
		);

		$ids = array_filter(
			$ids,
			static function ( $id ) {
				return $id > 0;
			}
		);

		return array_values( $ids );
	}

	/**
	 * Convert settings to React props.
	 *
	 * @param array $settings   Settings array with PHP keys.
	 * @param string $parent_key The parent key for nested arrays (used to determine which key map to use).
	 *
	 * @return array Transformed settings with camelCase keys.
	 */
	public static function to_react_props( $settings, $parent_key = null ) {
		if ( empty( $settings ) || ! is_array( $settings ) ) {
			return array();
		}

		// Allow DTOs to hydrate/reshape values for React before normalizing types.
		$settings = self::prepare_for_react( $settings, $parent_key );

		return parent::to_react_props( $settings, $parent_key );
	}

	/**
	 * Prepare raw stored settings for React.
	 *
	 * Child DTOs can override this to hydrate/reshape values into UI-friendly structures
	 * (e.g. turn attachment IDs into { id, url } objects) before key mapping occurs.
	 *
	 * @param array       $settings   Settings array with PHP keys.
	 * @param string|null $parent_key Parent key context.
	 *
	 * @return array
	 */
	protected static function prepare_for_react( $settings, $parent_key = null ) {
		if ( 'spinner' === $parent_key || 'placeholder' === $parent_key ) {
			if ( ! empty( $settings['custom'] ) && is_array( $settings['custom'] ) ) {
				$settings['custom'] = self::hydrate_attachments_for_react( $settings['custom'], 'full' );
			}
			return $settings;
		}

		return $settings;
	}

	/**
	 * Hydrate an array of attachment IDs (or objects containing an `id`) into
	 * an array of objects that include `id` and `url`.
	 *
	 * @param array  $items Attachment IDs or arrays containing 'id'.
	 * @param string $size  Image size to retrieve URL for.
	 *
	 * @return array
	 */
	private static function hydrate_attachments_for_react( $items, $size = 'full' ) {
		if ( empty( $items ) || ! is_array( $items ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					function ( $attachment_id ) use ( $size ) {
						$attachment_id = isset( $attachment_id['id'] ) ? $attachment_id['id'] : $attachment_id;
						$attachment_id = is_numeric( $attachment_id ) ? (int) $attachment_id : 0;
						$url           = '';

						if ( $attachment_id > 0 ) {
							$src = wp_get_attachment_image_src( $attachment_id, $size );
							$url = is_array( $src ) && ! empty( $src[0] ) ? (string) $src[0] : '';
							if ( empty( $url ) ) {
								$url = (string) wp_get_attachment_url( $attachment_id );
							}
						}

						if ( $attachment_id <= 0 ) {
							return null;
						}

						return array(
							'id'  => $attachment_id,
							'url' => $url,
						);
					},
					$items
				),
				static function ( $item ) {
					return ! is_null( $item );
				}
			)
		);
	}
}