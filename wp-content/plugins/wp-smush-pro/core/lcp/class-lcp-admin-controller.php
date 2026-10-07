<?php

namespace Smush\Core\LCP;

use Smush\Core\Controller;
use Smush\Core\Helper;
use Smush\Core\Settings;

class LCP_Admin_Controller extends Controller {
	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var LCP_Helper
	 */
	private $lcp_helper;

	public function __construct() {
		$this->settings   = Settings::get_instance();
		$this->lcp_helper = new LCP_Helper();

		$this->register_filter( 'wp_smush_localize_ui_script_data_smush_lazy_preload', array( $this, 'localize_preload_script_data' ) );
		$this->register_filter( 'wp_smush_sync_settings', array( $this, 'handle_settings_sync' ), 10, 3 );
		$this->register_action( 'edit_post', array( $this, 'clear_post_lcp_data' ) );
		$this->register_action( 'wp_ajax_clear_all_lcp_data', array( $this, 'ajax_mark_all_lcp_data_as_dirty' ) );
	}

	/**
	 * Localize preload settings for React.
	 *
	 * @param array $localize Current localize data.
	 *
	 * @return array
	 */
	public function localize_preload_script_data( $localize ) {
		if ( ! is_admin() ) {
			return $localize;
		}

		// Get preload settings from both sources
		$preload_settings = array();

		// Get preload_images from main settings
		foreach ( $this->settings->get_preload_fields() as $field ) {
			$preload_settings[ $field ] = $this->settings->get( $field );
		}

		// Get additional preload settings from wp-smush-preload option
		$preload_option   = $this->lcp_helper->get_preload_options();
		$preload_settings = array_merge( $preload_settings, $preload_option );

		// Transform using DTO
		$localize['preloadSettings'] = Preload_Settings_DTO::to_react_props( $preload_settings );

		return $localize;
	}

	/**
	 * Handle preload settings sync via unified endpoint.
	 *
	 * @param array|null $saved_settings Saved settings from previous filter, or null.
	 * @param array $settings Incoming settings from React (camelCase).
	 * @param string $context Context identifier.
	 *
	 * @return array|null Saved settings array if context matches, otherwise pass through.
	 *
	 * @since 3.25.0
	 */
	public function handle_settings_sync( $saved_settings, $settings, $context ) {
		// Only handle preload context
		if ( 'preload' !== $context ) {
			return $saved_settings;
		}

		// Convert React camelCase to PHP format using DTO
		$db_settings = Preload_Settings_DTO::from_react_props( $settings );

		// Preload settings come from two places:
		// 1. preload_images from main settings
		// 2. Other settings from wp-smush-preload option

		$preload_fields = $this->settings->get_preload_fields();

		// Save preload_images to main settings if present
		foreach ( $preload_fields as $field ) {
			if ( isset( $db_settings[ $field ] ) ) {
				$this->settings->set( $field, $db_settings[ $field ] );
				unset( $db_settings[ $field ] );
			}
		}

		// Save remaining settings to wp-smush-preload option
		if ( ! empty( $db_settings ) ) {
			$current_preload_settings = $this->lcp_helper->get_preload_options();
			$updated_preload_settings = array_merge( $current_preload_settings, $db_settings );
			$this->settings->set_setting( 'wp-smush-preload', $updated_preload_settings );
		}

		// Gather all preload settings for response
		$all_preload_settings = array();
		foreach ( $preload_fields as $field ) {
			$all_preload_settings[ $field ] = $this->settings->get( $field );
		}
		$preload_option       = $this->lcp_helper->get_preload_options();
		$all_preload_settings = array_merge( $all_preload_settings, $preload_option );

		// Return transformed data
		return Preload_Settings_DTO::to_react_props( $all_preload_settings );
	}

	public function clear_post_lcp_data( $post_id ) {
		$data_store = new LCP_Data_Store_Post_Meta();
		$data_store->set_post_id( $post_id );
		$data_store->delete_all();

		$data_store_home = new LCP_Data_Store_Home();
		$data_store_home->delete_all();
	}

	public function ajax_mark_all_lcp_data_as_dirty() {
		if ( ! check_ajax_referer( 'wp-smush-ajax', '_ajax_nonce', false ) ) {
			wp_send_json_error( array(
				'error_msg' => esc_html__( 'Nonce verification failed.', 'wp-smushit' ),
			) );
		}

		if ( ! Helper::is_user_allowed( 'manage_options' ) || ! $this->settings->has_lazy_preload_page() ) {
			wp_send_json_error( array(
				'error_msg' => esc_html__( 'Unauthorized', 'wp-smushit' ),
			) );
		}

		$this->mark_all_lcp_data_as_dirty();
	}

	private function mark_all_lcp_data_as_dirty() {
		$this->lcp_helper->increment_lcp_data_version();
	}
}