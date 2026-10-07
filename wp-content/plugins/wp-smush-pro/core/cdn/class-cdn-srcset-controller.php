<?php
/**
 * CDN class: CDN
 *
 * @package Smush\Core\Modules
 * @version 3.0
 */

namespace Smush\Core\CDN;

use Smush\Core\Controller;
use Smush\Core\Media\Media_Item_Cache;
use Smush\Core\Modules\Helpers;
use Smush\Core\Settings;
use Smush\Core\Srcset\Srcset_Helper;
use Smush\Core\Url_Utils;
use stdClass;
use WP_Error;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class CDN
 * TODO: cleanup everything that has been moved to the transform class
 */
class CDN_Srcset_Controller extends Controller {

	/**
	 * Module slug.
	 *
	 * @var string
	 */
	protected $slug = 'cdn';

	/**
	 * Whether module is pro or not.
	 *
	 * @var string
	 */
	protected $is_pro = true;

	/**
	 * Supported file extensions.
	 *
	 * @var array $supported_extensions
	 */
	private $supported_extensions = array(
		'gif',
		'jpg',
		'jpeg',
		'png',
		'webp',
	);

	/**
	 * @var CDN_Helper
	 */
	private $cdn_helper;
	/**
	 * @var Settings|null
	 */
	private $settings;
	/**
	 * Static instance
	 *
	 * @var self
	 */
	private static $instance;
	/**
	 * @var Url_Utils
	 */
	private $urls_utils;
	/**
	 * @var Srcset_Helper
	 */
	private $srcset_helper;

	/**
	 * @var Media_Item_Cache
	 */
	private $media_item_cache;

	/**
	 * Static instance getter
	 */
	public static function get_instance() {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * CDN constructor.
	 *
	 * @since 3.2.2
	 */
	public function __construct() {
		$this->settings         = Settings::get_instance();
		$this->cdn_helper       = CDN_Helper::get_instance();
		$this->urls_utils       = new Url_Utils();
		$this->srcset_helper    = Srcset_Helper::get_instance();
		$this->media_item_cache = Media_Item_Cache::get_instance();

		$this->register_filter( 'wp_calculate_image_srcset', array( $this, 'update_image_srcset_in_ajax' ), $this->get_cdn_srcset_priority(), 5 );
	}

	public function __call( $method_name, $arguments ) {
		_deprecated_function( esc_html( $method_name ), '4.2.0' );
	}

	public function should_run() {
		return ! is_admin() && $this->cdn_helper->is_cdn_active();
	}

	public function update_image_srcset_in_ajax( $sources, $size_array, $image_src, $image_meta, $attachment_id = 0 ) {
		if ( wp_doing_ajax() ) {
			$sources = $this->update_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id );
		}

		return $sources;
	}

	/**
	 * Filters an array of image srcset values, replacing each URL with resized CDN urls.
	 *
	 * Keep the existing srcset sizes if already added by WP, then calculate extra sizes
	 * if required.
	 *
	 * @param array $sources One or more arrays of source data to include in the 'srcset'.
	 * @param array $size_array Array of width and height values in pixels.
	 * @param string $image_src The 'src' of the image.
	 * @param array $image_meta The image metadata as returned by 'wp_get_attachment_metadata()'.
	 * @param int $attachment_id Image attachment ID or 0.
	 *
	 * @return array $sources
	 * @since 3.0
	 *
	 */
	public function update_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id = 0 ) {
		if (
			! $this->cdn_helper->is_supported_url( $image_src )
			|| $this->cdn_helper->skip_image_url( $image_src )
		) {
			return $sources;
		}

		if ( empty( $sources ) ) {
			$width   = $size_array[0];
			$sources = array(
				$width => array(
					'url'        => $image_src,
					'descriptor' => 'w',
					'value'      => $width,
				),
			);
		}

		$main_image_url = $this->get_largest_same_ratio_image_url( $image_src, $size_array, $attachment_id, $image_meta );

		foreach ( $sources as $i => $source ) {
			if ( ! $this->is_valid_url( $source['url'] ) || $this->cdn_helper->skip_image_url( $source['url'] ) ) {
				continue;
			}

			list( $width, $height ) = $this->get_size_from_file_name( $source['url'] );

			// The file already has a resized version as a thumbnail.
			if ( 'w' === $source['descriptor'] && $width === (int) $source['value'] ) {
				$sources[ $i ]['url'] = $this->cdn_helper->generate_cdn_url( $source['url'] );
				continue;
			}

			// If don't have attachment id, get original image by removing dimensions from url.
			if ( empty( $url ) ) {
				$url = $this->urls_utils->get_url_without_dimensions( $source['url'] );
			}

			$args = array();
			// If we got size from url, add them.
			if ( ! empty( $width ) && ! empty( $height ) ) {
				// Set size arg.
				$args = array(
					'size' => "{$width}x{$height}",
				);
			}

			// Replace with CDN url.
			$sources[ $i ]['url'] = $this->cdn_helper->generate_cdn_url( $url, $args );
		}

		// Set additional sizes if required.
		$should_add_additional_srcset = $this->cdn_helper->is_dynamic_sizes_active();
		if ( $should_add_additional_srcset ) {
			$sources = $this->set_additional_srcset( $sources, $size_array, $main_image_url, $image_meta, $image_src );
		}

		return $sources;
	}

	private function get_largest_same_ratio_image_url( $image_src, $size_array, $attachment_id, $image_meta = array() ) {
		if ( ! $attachment_id || ! is_array( $size_array ) || count( $size_array ) < 2 ) {
			return $image_src;
		}

		list( $original_width, $original_height ) = $size_array;
		if ( ! $original_width || ! $original_height ) {
			return $image_src;
		}

		$original_ratio = round( $original_width / $original_height, 4 );

		$media_item   = $this->media_item_cache->get( $attachment_id );
		$prev_area    = 0;
		$selected_url = $image_src;
		foreach ( $media_item->get_sizes() as $size ) {
			$width  = $size->get_width();
			$height = $size->get_height();
			if ( ! $width || ! $height ) {
				continue;
			}
			$area  = $width * $height;
			$ratio = round( $width / $height, 4 );
			if ( $area > $prev_area && wp_fuzzy_number_match( $ratio, $original_ratio, 0.1 ) ) {
				$selected_url = $size->get_file_url();
				$prev_area    = $area;
			}
		}

		return $selected_url;
	}

	/**
	 * Check if we can use the image URL in CDN.
	 *
	 * @param string $url Image URL.
	 *
	 * @return bool
	 * @since 3.0
	 *
	 */
	private function is_valid_url( $url ) {
		$parsed_url = wp_parse_url( $url );

		if ( ! $parsed_url ) {
			return false;
		}

		// No host or path found.
		if ( ! isset( $parsed_url['host'] ) || ! isset( $parsed_url['path'] ) ) {
			return false;
		}

		// If not supported extension - return false.
		if ( ! in_array( strtolower( pathinfo( $parsed_url['path'], PATHINFO_EXTENSION ) ), $this->supported_extensions, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Try to determine height and width from strings WP appends to resized image filenames.
	 *
	 * @param string $src The image URL.
	 *
	 * @return array An array consisting of width and height.
	 * @since 3.0
	 *
	 */
	private function get_size_from_file_name( $src ) {
		$size = array();

		if ( preg_match( '/-(\d+)x(\d+)\.(?:' . implode( '|', $this->supported_extensions ) . ')$/i', $src, $size ) ) {
			// Get size and width.
			$width  = (int) $size[1];
			$height = (int) $size[2];

			// Handle retina images.
			if ( strpos( $src, '@2x' ) ) {
				$width  = 2 * $width;
				$height = 2 * $height;
			}

			// Return width and height as array.
			if ( $width && $height ) {
				return array( $width, $height );
			}
		}

		return array( false, false );
	}

	/**
	 * Filters an array of image srcset values, and add additional values.
	 *
	 * @param array $sources An array of image urls and widths.
	 * @param array $size_array Array of width and height values in pixels.
	 * @param string $url Image URL.
	 * @param array $image_meta The image metadata.
	 * @param string $image_src The src of the image.
	 *
	 * @return array $sources
	 * @since 3.0
	 *
	 */
	private function set_additional_srcset( $sources, $size_array, $url, $image_meta, $image_src = '' ) {
		$content_width = $this->settings->max_content_width();

		// If url is empty, try to get from src.
		if ( empty( $url ) ) {
			$url = $this->urls_utils->get_url_without_dimensions( $image_src );
		}

		// We need to add additional dimensions.
		$full_width     = $image_meta['width'];
		$full_height    = $image_meta['height'];
		$current_width  = $size_array[0];
		$current_height = $size_array[1];
		// Get width and height calculated by WP.
		list( $constrained_width, $constrained_height ) = wp_constrain_dimensions( $full_width, $full_height, $current_width, $current_height );

		// Calculate base width.
		// If $constrained_width sizes are smaller than current size, set maximum content width.
		if ( abs( $constrained_width - $current_width ) <= 1 && abs( $constrained_height - $current_height ) <= 1 ) {
			$base_width = $content_width;
		} else {
			$base_width = $current_width;
		}

		$current_widths = array_keys( $sources );
		$new_sources    = array();

		/**
		 * Filter to add/update/bypass additional srcsets.
		 *
		 * If empty value or false is retured, additional srcset
		 * will not be generated.
		 *
		 * @param array|bool $additional_multipliers Additional multipliers.
		 */
		$additional_multipliers = apply_filters(
			'smush_srcset_additional_multipliers',
			array(
				0.2,
				0.4,
				0.6,
				0.8,
				1,
				1.5,
				2,
				3,
			)
		);

		// Continue only if additional multipliers found or not skipped.
		// Filter already documented in class-cdn.php.
		if ( $this->cdn_helper->skip_image_url( $url, false ) || empty( $additional_multipliers ) ) {
			return $sources;
		}

		// Loop through each multipliers and generate image.
		foreach ( $additional_multipliers as $multiplier ) {
			// New width by multiplying with original size.
			$new_width = (int) ( $base_width * $multiplier );

			// In most cases - going over the current width is not recommended and probably not what the user is expecting.
			if ( $new_width > $current_width ) {
				continue;
			}

			// If a nearly sized image already exist, skip.
			foreach ( $current_widths as $_width ) {
				// If +- 50 pixel difference - skip.
				if ( abs( $_width - $new_width ) < 50 || ( $new_width > $full_width ) ) {
					continue 2;
				}
			}

			// We need the width as well...
			$dimensions = wp_constrain_dimensions( $current_width, $current_height, $new_width );

			// Arguments for cdn url.
			$args = array(
				'size' => "{$new_width}x{$dimensions[1]}",
			);

			// Add new srcset item.
			$new_sources[ $new_width ] = array(
				'url'        => $this->cdn_helper->generate_cdn_url( $url, $args ),
				'descriptor' => 'w',
				'value'      => $new_width,
			);
		}

		// Assign new srcset items to existing ones.
		if ( ! empty( $new_sources ) ) {
			// Loop through each items and replace/add.
			foreach ( $new_sources as $_width_key => $_width_values ) {
				$sources[ $_width_key ] = $_width_values;
			}
		}

		return $sources;
	}

	/**
	 * Try to generate the srcset for the image.
	 *
	 * @param string $src Image source.
	 *
	 * @return array|bool
	 * @since 3.0
	 *
	 */
	public function generate_srcset( $src, $attachment_id = 0, $width = 0, $height = 0 ) {
		$priority = $this->get_cdn_srcset_priority();
		add_filter( 'wp_calculate_image_srcset', array( $this, 'update_image_srcset' ), $priority, 5 );
		list( $srcset, $sizes ) = $this->srcset_helper->generate_srcset_and_sizes(
			$src,
			$attachment_id,
			$width,
			$height
		);
		remove_filter( 'wp_calculate_image_srcset', array( $this, 'update_image_srcset' ), $priority );

		return array( $srcset, $sizes );
	}

	/**
	 * @return int
	 */
	private function get_cdn_srcset_priority() {
		return defined( 'WP_SMUSH_CDN_DELAY_SRCSET' ) && WP_SMUSH_CDN_DELAY_SRCSET ? 1000 : 99;
	}

}