<?php

namespace Smush\Core\Avif;

use Smush\Core\Controller;
use Smush\Core\Helper;
use Smush\Core\Media\Media_Item_Cache;
use Smush\Core\Settings;
use Smush\Core\Stats\Global_Stats;
use Smush\Core\Stats\Media_Item_Optimization_Global_Stats_Persistable;

class Avif_Controller extends Controller {
	private static $avif_optimization_order = 30;
	private static $avif_transform_priority = 40;
	private static $avif_configuration_order = 20;
	private static $global_stats_option_id = 'wp-smush-avif-global-stats';

	/**
	 * @var Avif_Helper
	 */
	private $helper;
	/**
	 * @var Media_Item_Cache
	 */
	private $media_item_cache;
	/**
	 * @var \WDEV_Logger
	 */
	private $logger;
	/**
	 * @var Global_Stats
	 */
	private $global_stats;
	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var Avif_Configuration
	 */
	private $configuration;

	public function __construct() {
		$this->helper           = new Avif_Helper();
		$this->media_item_cache = Media_Item_Cache::get_instance();
		$this->logger           = Helper::logger();
		$this->global_stats     = Global_Stats::get();
		$this->settings         = Settings::get_instance();
		$this->configuration    = new Avif_Configuration();

		$this->register_filter( 'wp_smush_optimizations', array(
			$this,
			'add_avif_optimization',
		), self::$avif_optimization_order, 2 );

		$this->register_filter( 'wp_smush_content_transforms', array(
			$this,
			'add_avif_transform',
		), self::$avif_transform_priority );

		$this->register_filter( 'wp_smush_next_gen_configuration_objects', array(
			$this,
			'add_avif_configuration',
		), self::$avif_configuration_order );

		$this->register_action( 'wp_smush_before_restore_backup', array(
			$this,
			'delete_avif_versions_on_restore',
		), 10, 2 );

		$this->register_action( 'wp_smush_png_jpg_converted', array( $this, 'delete_avif_versions_of_pngs' ), 10, 4 );
		$this->register_action( 'delete_attachment', array( $this, 'delete_avif_versions_before_delete' ) );
		$this->register_filter( 'wp_smush_global_optimization_stats', array( $this, 'add_avif_global_stats' ) );
		$this->register_filter( 'wp_smush_global_stats_digest_keys', array( $this, 'add_digest_keys' ) );
		$this->register_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_fallback_js' ) );
		$this->register_action( 'wp_ajax_smush_avif_delete_all', array( $this, 'ajax_delete_all_avif_files' ) );
	}

	public function maybe_enqueue_fallback_js() {
		if ( ! $this->settings->is_avif_fallback_active() ) {
			return;
		}
		$handle = 'smush-nextgen-fallback';
		wp_enqueue_script(
			$handle,
			WP_SMUSH_URL . 'app/assets/js/smush-nextgen-fallback.min.js',
			array(),
			WP_SMUSH_VERSION,
			true
		);
		wp_localize_script( $handle, 'wp_smushit_nextgen_data', array(
			'mode' => 'avif',
		) );
	}

	public function add_avif_optimization( $optimizations, $media_item ) {
		$avif_optimization                              = new Avif_Optimization( $media_item );
		$optimizations[ $avif_optimization->get_key() ] = $avif_optimization;

		return $optimizations;
	}

	public function add_avif_transform( $transforms ) {
		$transforms['avif'] = new Avif_Transform();

		return $transforms;
	}

	/**
	 * @param $backup_full_path
	 * @param $attachment_id
	 *
	 * @return bool
	 */
	public function delete_avif_versions_on_restore( $backup_full_path, $attachment_id ) {
		$media_item = $this->media_item_cache->get( $attachment_id );
		if ( ! $media_item->is_valid() ) {
			return false;
		}

		$this->helper->delete_media_item_avif_versions( $media_item );

		return true;
	}

	public function delete_avif_versions_of_pngs( $attachment_id, $meta, $stats, $png_paths ) {
		foreach ( $png_paths as $png_path ) {
			$this->helper->delete_avif_version( $png_path );
		}

		$this->delete_avif_meta( $attachment_id );
	}

	private function delete_avif_meta( $attachment_id ) {
		$media_item = $this->media_item_cache->get( $attachment_id );
		if ( $media_item->is_valid() ) {
			$avif_optimization = new Avif_Optimization( $media_item );
			$avif_optimization->delete_data();
		}
	}

	public function delete_avif_versions_before_delete( $attachment_id ) {
		$media_item = $this->media_item_cache->get( $attachment_id );
		if ( $media_item->is_valid() ) {
			$this->helper->delete_media_item_avif_versions( $media_item );
		} else {
			$this->logger->error( sprintf( 'Count not delete avif versions of the media item [%d]', $attachment_id ) );
		}
	}

	public function add_avif_global_stats( $stats ) {
		$stats[ Avif_Optimization::get_key() ] = new Media_Item_Optimization_Global_Stats_Persistable( self::$global_stats_option_id );

		return $stats;
	}

	public function add_digest_keys( $keys ) {
		$keys[] = 'avif_mod';

		return $keys;
	}

	public function add_avif_configuration( $modules ) {
		$modules[ $this->configuration->get_format_key() ] = $this->configuration;

		return $modules;
	}

	/**
	 * Delete all avif images.
	 * Triggered by the "Delete AVIF images" button in the avif tab.
	 */
	public function ajax_delete_all_avif_files() {
		check_ajax_referer( 'wp-smush-ajax' );

		$capability = is_multisite() ? 'manage_network' : 'manage_options';

		if ( ! Helper::is_user_allowed( $capability ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'This user can not delete all AVIF images.', 'wp-smushit' ),
				),
				403
			);
		}

		$this->helper->delete_all_avif_files();

		wp_send_json_success();
	}
}