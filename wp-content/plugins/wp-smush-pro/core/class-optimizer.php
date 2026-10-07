<?php

namespace Smush\Core;

use Smush\Core\Media\Media_Item_Cache;
use Smush\Core\Media\Media_Item_Optimizer;
use Smush\Core\Membership\Membership;
use Smush\Core\Smush\Smusher;
use Smush\Core\Smush\Smusher_Options;
use Smush\Core\Smush\Smusher_Options_Provider;
use Smush\Core\Webp\Webp_Converter;
use WP_Error;

/**
 * This is a light weight facade that acts as the first entry point for optimization. The real work is done by {@see Media_Item_Optimizer}.
 */
class Optimizer {
	/**
	 * Static instance
	 *
	 * @var self
	 */
	private static $instance;
	/**
	 * @var bool
	 */
	private $optimization_in_progress;
	/**
	 * @var Media_Item_Cache
	 */
	private $media_item_cache;
	/**
	 * @var \WP_Error
	 */
	private $errors;
	private $membership;
	private $settings;

	public static function get_instance() {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->media_item_cache = Media_Item_Cache::get_instance();
		$this->errors           = new \WP_Error();
		$this->membership       = Membership::get_instance();
		$this->settings         = Settings::get_instance();
	}

	public function should_auto_optimize( $attachment_id ) {
		if ( $this->membership->is_api_hub_access_required() ) {
			return false;
		}

		if ( ! $this->settings->is_automatic_compression_active() ) {
			return false;
		}

		$media_item = $this->media_item_cache->get( $attachment_id );
		if ( ! $media_item->is_valid() ) {
			return false;
		}

		/**
		 * Skip auto smush filter.
		 *
		 * @param bool $skip_auto_smush Whether to skip auto smush or not.
		 */
		$skip_auto_smush = apply_filters( 'wp_smush_should_skip_auto_smush', false, $attachment_id );

		// We don't want very large files to be auto smushed.
		$skip_auto_smush = $skip_auto_smush || $media_item->is_large();
		if ( $skip_auto_smush ) {
			return false;
		}

		return true;
	}

	public function optimize( $attachment_id ) {
		if ( $this->optimization_in_progress ) {
			$this->set_errors( new WP_Error( 'in_progress', 'Smush already in progress' ) );
			return false;
		}

		$this->optimization_in_progress = true;

		// Reset the errors before starting
		$this->set_errors( null );

		$media_item           = $this->media_item_cache->get( $attachment_id );
		$media_item_optimizer = new Media_Item_Optimizer( $media_item );
		$optimized            = $media_item_optimizer->optimize();
		if ( ! $optimized ) {
			$errors = $media_item->has_errors()
				? $media_item->get_errors()
				: $media_item_optimizer->get_errors();
			$this->set_errors( $errors );
		}

		$this->optimization_in_progress = false;

		return $optimized;
	}

	public function optimize_file( $file_path, $convert_to_webp = false, $options = null ) {
		$smusher_options = $options ?? ( new Smusher_Options_Provider() )->get_options();
		$smusher         = $convert_to_webp
			? new Webp_Converter( $smusher_options )
			: new Smusher( $smusher_options );

		$data = $smusher->validate_and_smush_file( $file_path );
		if ( $data ) {
			return array( 'success' => true, 'data' => $data );
		} else {
			return $smusher->get_errors();
		}
	}

	public function get_errors() {
		if ( is_null( $this->errors ) ) {
			$this->errors = new WP_Error();
		}
		return $this->errors;
	}

	private function set_errors( $error ) {
		$this->errors = $error;
	}
}