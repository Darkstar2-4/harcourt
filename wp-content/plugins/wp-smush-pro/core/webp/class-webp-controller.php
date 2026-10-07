<?php

namespace Smush\Core\Webp;

use Smush\Core\Controller;
use Smush\Core\File_System;
use Smush\Core\Helper;
use Smush\Core\Media\Media_Item_Cache;
use Smush\Core\Settings;
use Smush\Core\Stats\Global_Stats;
use Smush\Core\Stats\Media_Item_Optimization_Global_Stats_Persistable;

class Webp_Controller extends Controller {
	private static $webp_optimization_order = 20;
	private static $webp_transform_priority = 50;
	private static $webp_configuration_order = 10;
	private static $global_stats_option_id = 'wp-smush-webp-global-stats';
	/**
	 * @var Webp_Helper
	 */
	private $helper;
	/**
	 * @var Global_Stats
	 */
	private $global_stats;
	/**
	 * @var Media_Item_Cache
	 */
	private $media_item_cache;
	/**
	 * @var \WDEV_Logger|null
	 */
	private $logger;
	/**
	 * @var File_System
	 */
	private $fs;
	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var Webp_Configuration
	 */
	private $configuration;


	public function __construct() {
		$this->helper           = new Webp_Helper();
		$this->global_stats     = Global_Stats::get();
		$this->media_item_cache = Media_Item_Cache::get_instance();
		$this->logger           = Helper::logger();
		$this->fs               = new File_System();
		$this->configuration    = Webp_Configuration::get_instance();
		$this->settings         = Settings::get_instance();

		$this->register_action( 'wp_smush_png_jpg_converted', array( $this, 'delete_webp_versions_of_pngs' ), 10, 4 );
		$this->register_action( 'delete_attachment', array( $this, 'delete_webp_versions_before_delete' ) );
		$this->register_filter( 'wp_smush_optimizations', array(
			$this,
			'add_webp_optimization',
		), self::$webp_optimization_order, 2 );
		$this->register_filter( 'wp_smush_global_optimization_stats', array( $this, 'add_webp_global_stats' ) );
		$this->register_filter( 'wp_smush_global_stats_digest_keys', array( $this, 'add_digest_keys' ) );
		$this->register_action( 'wp_smush_before_restore_backup', array(
			$this,
			'delete_webp_versions_on_restore',
		), 10, 2 );
		$this->register_filter( 'wp_smush_content_transforms', array(
			$this,
			'add_webp_transform',
		), self::$webp_transform_priority );

		$this->register_filter( 'wp_smush_next_gen_configuration_objects', array(
			$this,
			'add_webp_configuration',
		), self::$webp_configuration_order );

		/** Ajax actions */
		$this->register_action( 'wp_ajax_smush_webp_get_status', array(
			$this,
			'ajax_get_server_configuration_status',
		) );
		$this->register_action( 'wp_ajax_smush_webp_apply_htaccess_rules', array(
			$this,
			'ajax_apply_htaccess_rules',
		) );
		$this->register_action( 'wp_ajax_smush_webp_delete_all', array( $this, 'ajax_delete_all_webp_files' ) );
		$this->register_action( 'wp_ajax_smush_hide_webp_wizard', array( $this, 'ajax_hide_wizard' ) );
		$this->register_action( 'wp_ajax_smush_show_webp_wizard', array( $this, 'ajax_show_wizard' ) );
		// TODO: clean rules from .htaccess on deactivate plugin.

		$this->register_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_fallback_js' ) );

		$this->register_action( 'wp_smush_after_delete_all_webp_files', array(
			$this,
			'maybe_revert_lock_file_on_delete_webp_files',
		) );
	}

	/**
	 * @param $backup_full_path
	 * @param $attachment_id
	 *
	 * @return bool
	 */
	public function delete_webp_versions_on_restore( $backup_full_path, $attachment_id ) {
		$media_item = Media_Item_Cache::get_instance()->get( $attachment_id );
		if ( ! $media_item->is_valid() ) {
			return false;
		}

		$this->helper->delete_media_item_webp_versions( $media_item );

		return true;
	}

	public function delete_webp_versions_before_delete( $attachment_id ) {
		$media_item = $this->media_item_cache->get( $attachment_id );
		if ( $media_item->is_valid() ) {
			$this->helper->delete_media_item_webp_versions( $media_item );
		} else {
			$this->logger->error( sprintf( 'Count not delete webp versions of the media item [%d]', $attachment_id ) );
		}
	}

	public function delete_webp_versions_of_pngs( $attachment_id, $meta, $stats, $png_paths ) {
		foreach ( $png_paths as $png_path ) {
			$this->helper->delete_webp_version( $png_path );
		}

		$media_item = $this->media_item_cache->get( $attachment_id );
		if ( $media_item ) {
			$webp_optimization = new Webp_Optimization( $media_item );
			$webp_optimization->delete_data();
		}
	}

	public function add_webp_optimization( $optimizations, $media_item ) {
		$optimization                              = new Webp_Optimization( $media_item );
		$optimizations[ $optimization->get_key() ] = $optimization;

		return $optimizations;
	}

	public function add_webp_global_stats( $stats ) {
		$stats[ Webp_Optimization::get_key() ] = new Media_Item_Optimization_Global_Stats_Persistable( self::$global_stats_option_id );

		return $stats;
	}

	public function add_digest_keys( $keys ) {
		$keys[] = 'webp_mod';

		return $keys;
	}

	/**
	 * Check server configuration status and other info for WebP.
	 *
	 * Handles "Re-Check Status" button press on the WebP meta box.
	 */
	public function ajax_get_server_configuration_status() {
		$capability = is_multisite() ? 'manage_network' : 'manage_options';
		if (
			! check_ajax_referer( 'wp-smush-ajax', false, false ) ||
			! Helper::is_user_allowed( $capability )
		) {
			wp_send_json_error( esc_html__( "Either the nonce expired or you can't modify options. Please reload the page and try again.", 'wp-smushit' ) );
		}

		if ( $this->configuration->is_configured() ) {
			wp_send_json_success();
		}

		$error_message = $this->configuration->server_configuration()->get_configuration_message();
		wp_send_json_error( esc_html( $error_message ) );
	}

	/**
	 * Write apache rules for WebP support from .htaccess file.
	 * Handles the "Apply Rules" button press on the WebP meta box.
	 */
	public function ajax_apply_htaccess_rules() {
		$capability = is_multisite() ? 'manage_network' : 'manage_options';
		if (
			! check_ajax_referer( 'wp-smush-ajax', false, false )
			|| ! Helper::is_user_allowed( $capability )
		) {
			wp_send_json_error( esc_html__( "Either the nonce expired or you can't modify options. Please reload the page and try again.", 'wp-smushit' ) );
		}

		$last_error = $this->configuration->server_configuration()->apply_apache_rewrite_rules();

		if ( ! empty( $last_error ) ) {
			wp_send_json_error( esc_html( $last_error ) );
		}

		wp_send_json_success();
	}

	/**
	 * Delete all webp images.
	 * Triggered by the "Delete WebP images" button in the webp tab.
	 */
	public function ajax_delete_all_webp_files() {
		check_ajax_referer( 'wp-smush-ajax' );
		$capability = is_multisite() ? 'manage_network' : 'manage_options';
		if (
			! Helper::is_user_allowed( $capability )
		) {
			wp_send_json_error(
				array(
					'message' => __( 'This user can not delete all WebP images.', 'wp-smushit' ),
				),
				403
			);
		}

		$this->helper->delete_all_webp_files();

		wp_send_json_success();
	}

	public function ajax_show_wizard() {
		$capability = is_multisite() ? 'manage_network' : 'manage_options';
		if (
			check_ajax_referer( 'wp-smush-ajax', false, false )
			&& Helper::is_user_allowed( $capability )
		) {
			$this->configuration->show_wizard();

			wp_send_json_success();
		}
	}

	public function ajax_hide_wizard() {
		$capability = is_multisite() ? 'manage_network' : 'manage_options';
		if (
			check_ajax_referer( 'wp-smush-ajax', false, false )
			&& Helper::is_user_allowed( $capability )
		) {
			$this->configuration->hide_wizard();

			wp_send_json_success();
		}
	}

	public function add_webp_transform( $transforms ) {
		$transforms['webp'] = new Webp_Transform();

		return $transforms;
	}

	public function maybe_enqueue_fallback_js() {
		$should_include = $this->settings->is_webp_fallback_active() && ! $this->settings->is_avif_fallback_active(); // Avif gets priority
		if ( ! $should_include ) {
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
			'mode' => 'webp',
		) );
	}

	public function maybe_revert_lock_file_on_delete_webp_files() {
		if ( $this->configuration->direct_conversion_enabled() ) {
			$this->configuration->server_configuration()->disable();
		}
	}

	public function add_webp_configuration( $configuration_modules ) {
		$configuration_modules[ $this->configuration->get_format_key() ] = $this->configuration;

		return $configuration_modules;
	}
}