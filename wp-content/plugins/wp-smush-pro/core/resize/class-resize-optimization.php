<?php

namespace Smush\Core\Resize;

use Smush\Core\Helper;
use Smush\Core\Media\Media_Item;
use Smush\Core\Media\Media_Item_Optimization;
use Smush\Core\Media\Media_Item_Size;
use Smush\Core\Settings;
use WDEV_Logger;
use WP_Error;
use WP_Image_Editor;

class Resize_Optimization extends Media_Item_Optimization {
	private static $key = 'resize_optimization';
	private static $meta_key = 'wp-smush-resize_savings';
	/**
	 * @var Media_Item
	 */
	private $media_item;
	/**
	 * @var Settings
	 */
	private $settings;
	/**
	 * @var \stdClass
	 */
	private $resize_dimensions;
	/**
	 * @var WDEV_Logger
	 */
	private $logger;
	/**
	 * @var Resize_Media_Item_Stats
	 */
	private $stats;
	private $savings_meta;
	private $size_stats;
	/**
	 * @var WP_Error
	 */
	private $errors;
	private $reset_properties = array(
		'stats',
		'savings_meta',
		'size_stats',
	);
	/**
	 * @var Resize_Helper
	 */
	private $resize_helper;

	public function __construct( $media_item ) {
		$this->media_item    = $media_item;
		$this->settings      = Settings::get_instance();
		$this->logger        = Helper::logger()->resize();
		$this->errors        = new WP_Error();
		$this->resize_helper = new Resize_Helper();
	}

	public static function get_key() {
		return self::$key;
	}

	public function get_name() {
		return __( 'Resize', 'wp-smushit' );
	}

	/**
	 * Get optimization statistics.
	 *
	 * @return Resize_Media_Item_Stats
	 */
	public function get_stats() {
		if ( is_null( $this->stats ) ) {
			$this->stats = $this->prepare_stats();
		}

		return $this->stats;
	}

	public function get_size_stats( $size_key ) {
		if ( is_null( $this->size_stats ) ) {
			$this->size_stats = new Resize_Media_Item_Stats();
		}

		return $this->size_stats;
	}

	public function save() {
		$meta = $this->make_meta();
		if ( ! empty( $meta ) ) {
			update_post_meta( $this->media_item->get_id(), self::$meta_key, $meta );
			$this->reset();
		}
	}

	private function make_meta() {
		$stats = $this->get_stats();

		return $stats->is_empty() ? array() : $stats->to_array();
	}

	public function is_optimized() {
		return ! $this->get_stats()->is_empty();
	}

	public function should_optimize() {
		if (
			$this->media_item->is_skipped()
			|| $this->media_item->has_errors()
			|| ! $this->settings->is_resize_module_active()
		) {
			return false;
		}

		return apply_filters(
			'wp_smush_resize_uploaded_image',
			$this->_should_optimize(),
			$this->media_item->get_id(),
			$this->get_savings_meta()
		);
	}

	private function _should_optimize() {
		$size          = $this->get_size_to_resize();
		$dimensions    = $this->get_resize_dimensions();
		$target_width  = (int) $dimensions->width;
		$target_height = (int) $dimensions->height;

		if ( strpos( $size->get_file_path(), 'noresize' ) !== false ) {
			return false;
		}

		$width_resizable  = $target_width > 0
		                    && $size->get_width() > $target_width
		                    && ! wp_fuzzy_number_match( $target_width, $size->get_width() );
		$height_resizable = $target_height > 0
		                    && $size->get_height() > $target_height
		                    && ! wp_fuzzy_number_match( $target_height, $size->get_height() );
		if ( $width_resizable || $height_resizable ) {
			return true;
		}

		return false;
	}

	public function should_reoptimize() {
		if ( ! $this->should_optimize() ) {
			return false;
		}

		$stats                 = $this->get_stats();
		$dimensions            = $this->get_resize_dimensions();
		$target_width          = (int) $dimensions->width;
		$target_height         = (int) $dimensions->height;
		$resizing_size_changed = $target_width !== $stats->get_resize_width()
							|| $target_height !== $stats->get_resize_height();

		return $resizing_size_changed;
	}

	public function optimize() {
		if ( ! $this->should_optimize() ) {
			return false;
		}

		return $this->resize_image();
	}

	/**
	 * @return Media_Item_Size
	 */
	private function get_size_to_resize() {
		return $this->media_item->get_main_size();
	}

	/**
	 * TODO: maybe it should only resize the full image and not the scaled image
	 *
	 * @return bool
	 */
	private function resize_image() {
		$media_item     = $this->media_item;
		$id             = $media_item->get_id();
		$size_to_resize = $this->get_size_to_resize();
		if ( ! $size_to_resize ) {
			/* translators: %d: Image id. */
			$this->add_error( 'no_size', sprintf( __( 'Could not find a suitable source image for resizing media item [%d].', 'wp-smushit' ), $id ) );

			return false;
		}

		$original_filesize = $size_to_resize->get_filesize();
		$dimensions        = $this->get_resize_dimensions();
		$file_resizer      = new File_Resizer(
			$size_to_resize->get_file_path(),
			$original_filesize,
			$dimensions->width,
			$dimensions->height
		);

		$resized = $file_resizer->resize();
		if ( ! $resized ) {
			$this->errors->merge_from( $file_resizer->get_errors() );

			// Update no savings stats.
			$this->update_stats( $original_filesize, $original_filesize );
			// Save resize meta.
			$this->save();

			return false;
		}

		// Update media item.
		$size_to_resize->set_filesize( $file_resizer->get_new_filesize() );
		$size_to_resize->set_width( $file_resizer->get_new_width() );
		$size_to_resize->set_height( $file_resizer->get_new_height() );
		$this->media_item->save();

		// Update the stats.
		$this->update_stats( $file_resizer->get_new_filesize(), $original_filesize );
		// Save resize meta.
		$this->save();

		do_action( 'wp_smush_image_resized', $id, $this->get_stats()->to_array() );

		return true;
	}

	/**
	 * Update resizing statistics.
	 *
	 * @param mixed $size_after New resized image filesize.
	 * @param mixed $size_before Original image filesize.
	 * @return void
	 */
	private function update_stats( $size_after, $size_before ) {
		$stats      = $this->get_stats();
		$dimensions = $this->get_resize_dimensions();
		// The image can be resized before, so make sure we don't lose the oldest stats.
		$size_before = max( $size_before, $stats->get_size_before() );
		$size_after  = $stats->get_size_after() > 0 ? min( $size_after, $stats->get_size_after() ) : $size_after;

		$stats->set_size_before( $size_before );
		$stats->set_size_after( $size_after );
		$stats->set_resize_width( $dimensions->width );
		$stats->set_resize_height( $dimensions->height );
	}

	public function get_errors() {
		return $this->errors;
	}

	private function add_error( $code, $message ) {
		$size     = $this->get_size_to_resize();
		$size_key = $size ? $size->get_key() : 'full';

		// Log the error
		$this->logger->error( $message );
		// Add the error
		$this->errors->add( $code, "[$size_key] $message" );
	}

	/**
	 * @return \stdClass
	 */
	private function get_resize_dimensions() {
		if ( is_null( $this->resize_dimensions ) ) {
			$this->resize_dimensions = $this->prepare_resize_dimensions();
		}

		return $this->resize_dimensions;
	}

	private function prepare_resize_dimensions() {
		return $this->resize_helper->get_resize_dimensions(
			$this->get_size_to_resize()->get_file_path(),
			$this->media_item->get_id()
		);
	}

	private function prepare_stats() {
		$stats = new Resize_Media_Item_Stats();
		$stats->from_array( $this->get_savings_meta() );

		return $stats;
	}

	private function get_savings_meta() {
		if ( is_null( $this->savings_meta ) ) {
			$this->savings_meta = $this->prepare_savings_meta();
		}

		return $this->savings_meta;
	}

	private function prepare_savings_meta() {
		$meta = get_post_meta( $this->media_item->get_id(), self::$meta_key, true );

		return empty( $meta )
			? array()
			: $meta;
	}

	/**
	 * @param Settings $settings
	 */
	public function set_settings( $settings ) {
		$this->settings = $settings;
	}

	public function delete_data() {
		delete_post_meta( $this->media_item->get_id(), self::$meta_key );

		$this->reset();
	}

	public function should_optimize_size( $size ) {
		if ( ! $this->should_optimize() ) {
			return false;
		}

		return $this->get_size_to_resize()->get_key() === $size->get_key();
	}

	private function reset() {
		foreach ( $this->reset_properties as $property ) {
			$this->$property = null;
		}
	}

	public function get_optimized_sizes_count() {
		// We always resize the largest available size only
		return $this->is_optimized() ? 1 : 0;
	}
}