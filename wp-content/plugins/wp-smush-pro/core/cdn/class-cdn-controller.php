<?php

namespace Smush\Core\CDN;

use Smush\Core\Controller;
use Smush\Core\Cron_Controller;
use Smush\Core\Helper;
use Smush\Core\Media\Attachment_Url_Cache;
use Smush\Core\Settings;
use Smush\Core\Url_Utils;
use WP_Error;
use WP_Smush;

class CDN_Controller extends Controller {
	private static $cdn_transform_priority = 10;
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
	private $url_utils;

	public function __construct() {
		$this->cdn_helper = CDN_Helper::get_instance();
		$this->settings   = Settings::get_instance();
		$this->url_utils  = new Url_Utils();

		$this->register_filter( 'wp_smush_content_transforms', array(
			$this,
			'register_cdn_transform',
		), self::$cdn_transform_priority );
		$this->register_action( 'wp_ajax_get_cdn_stats', array( $this, 'ajax_update_stats' ) );
		$this->register_action( 'wp_ajax_smush_toggle_cdn', array( $this, 'ajax_toggle_cdn' ) );
		$this->register_filter( 'wp_resource_hints', array( $this, 'dns_prefetch' ), 99, 2 );
		$this->register_filter( 'wp_smush_localize_ui_script_data', array( $this, 'localize_cdn_script_data' ) );
		$this->register_filter( 'wp_smush_sync_settings', array( $this, 'handle_settings_sync' ), 10, 3 );

		if ( $this->cdn_helper->is_cdn_active() ) {
			$this->register_action( Cron_Controller::get_cron_hook(), array( $this, 'cron_update_stats' ) );
			$this->register_filter( 'wp_smush_lcp_allowed_url_hostnames', array( $this, 'add_lcp_allowed_hostname' ), 10, 2 );
			$this->register_filter( 'wp_smush_get_image_dimensions', array( $this, 'find_image_dimensions_for_cdn_url' ), 10, 2 );
			$this->register_filter( 'wp_smush_get_image_dimensions_url', array( $this, 'return_original_url_from_image_dimensions' ) );
		}

		if ( $this->cdn_helper->is_dynamic_sizes_active() ) {
			// Dynamic sizes feature needs the URL cache to be primed
			Attachment_Url_Cache::get_instance()->set_fetch_in_advance( true );
		}
	}

	/**
	 * Static instance getter
	 */
	public static function get_instance() {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function ajax_update_stats() {
		check_ajax_referer( 'wp-smush-ajax' );
		$capatibility = is_multisite() ? 'manage_network' : 'manage_options';

		if ( ! Helper::is_user_allowed( $capatibility ) ) {
			wp_send_json_error( array(
				'message' => __( 'Unauthorized', 'wp-smushit' ),
			), 403 );
		}

		$status = $this->cdn_helper->get_cdn_status_setting();
		$smush  = WP_Smush::get_instance();
		if ( isset( $status->cdn_enabling ) && $status->cdn_enabling ) {
			$new_status = $this->process_cdn_status_response( $smush->api()->enable() );

			if ( is_wp_error( $new_status ) ) {
				$code = is_numeric( $new_status->get_error_code() ) ? $new_status->get_error_code() : null;
				wp_send_json_error( array(
					'message' => $new_status->get_error_message(),
				), $code );
			} else {
				$this->settings->set_setting( 'wp-smush-cdn_status', $new_status );
				wp_send_json_success( $this->cdn_settings_to_react_props( $new_status ) );
			}
		} else {
			wp_send_json_success( $this->cdn_settings_to_react_props( $status ) );
		}
	}

	public function cron_update_stats() {
		$status           = $this->cdn_helper->get_cdn_status_setting();
		$smush            = WP_Smush::get_instance();
		$cdn_enabling     = isset( $status->cdn_enabling ) && $status->cdn_enabling;
		$raw_api_response = $cdn_enabling ? $smush->api()->enable() : $smush->api()->check();
		$new_status       = $this->process_cdn_status_response( $raw_api_response );

		if ( $new_status && ! is_wp_error( $new_status ) ) {
			$this->settings->set_setting( 'wp-smush-cdn_status', $new_status );
		}
	}

	public function process_cdn_status_response( $status ) {
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$status = json_decode( $status['body'] );

		// Too many requests.
		if ( is_null( $status ) ) {
			return new WP_Error( 'too_many_requests', __( 'Too many requests, please try again in a moment.', 'wp-smushit' ) );
		}

		// Some other error from API.
		if ( ! $status->success ) {
			return new WP_Error( $status->data->error_code, $status->data->message );
		}

		return $status->data;
	}

	public function toggle_cdn( $enable ) {
		$this->settings->set( 'cdn', $enable );

		if ( $enable ) {
			$smush  = WP_Smush::get_instance();
			$status = $this->cdn_helper->get_cdn_status_setting();
			if ( empty( $status->endpoint_url ) ) {
				$enable_response = $this->process_cdn_status_response( $smush->api()->enable( true ) );
				if ( is_wp_error( $enable_response ) ) {
					return $enable_response;
				}

				$this->settings->set_setting( 'wp-smush-cdn_status', $enable_response );
			}
		} else {
			// Remove CDN settings if disabling.
			$this->settings->delete_setting( 'wp-smush-cdn_status' );
		}

		do_action( 'wp_smush_cdn_status_changed' );

		return true;
	}

	public function ajax_toggle_cdn() {
		check_ajax_referer( 'wp-smush-ajax' );

		if ( ! Helper::is_user_allowed() || ! $this->settings->has_cdn_page() ) {
			wp_send_json_error( array(
				'message' => __( 'User can not modify options', 'wp-smushit' ),
			), 403 );
		}

		$enable  = filter_input( INPUT_POST, 'param', FILTER_VALIDATE_BOOLEAN );
		$toggled = $this->toggle_cdn( $enable );

		if ( is_wp_error( $toggled ) ) {
			wp_send_json_error( array(
				'message' => $toggled->get_error_message(),
			) );
		}

		wp_send_json_success( $this->get_cdn_status_react_props() );
	}

	public function register_cdn_transform( $transforms ) {
		$transforms['cdn'] = new CDN_Transform();

		return $transforms;
	}

	/**
	 * Add CDN url to header for better speed.
	 *
	 * @param array $urls URLs to print for resource hints.
	 * @param string $relation_type The relation type the URLs are printed.
	 *
	 * @return array
	 * @since 3.0
	 */
	public function dns_prefetch( $urls, $relation_type ) {
		// Add only if CDN active.
		if ( 'dns-prefetch' === $relation_type && $this->cdn_helper->is_cdn_active() && ! empty( $this->cdn_helper->get_cdn_base_url() ) ) {
			$urls[] = $this->cdn_helper->get_cdn_base_url();
		}

		return $urls;
	}

	public function add_lcp_allowed_hostname( $hostnames ) {
		$cdn_base_url = $this->cdn_helper->get_cdn_base_url();
		if ( ! empty( $cdn_base_url ) ) {
			$cdn_hostname = parse_url( $cdn_base_url, PHP_URL_HOST );
			if ( ! in_array( $cdn_hostname, $hostnames, true ) ) {
				$hostnames[] = $cdn_hostname;
			}
		}

		return $hostnames;
	}

	public function find_image_dimensions_for_cdn_url( $actual_dimensions, $maybe_cdn_url ) {
		$dimensions = $actual_dimensions;
		if ( $this->cdn_helper->is_cdn_url( $maybe_cdn_url ) ) {
			$query_string = wp_parse_url( $maybe_cdn_url, PHP_URL_QUERY );
			parse_str( $query_string, $query_params );
			if ( ! empty( $query_params['size'] ) ) {
				$size_parts = explode( 'x', $query_params['size'] );
				if ( $size_parts && count( $size_parts ) === 2 ) {
					$dimensions = array( (int) $size_parts[0], (int) $size_parts[1] );
				}
			}
		}

		return $dimensions;
	}

	public function return_original_url_from_image_dimensions( $image_url ) {
		$original_url = false;
		if ( $this->cdn_helper->is_cdn_url( $image_url ) ) {
			$original_url = $this->cdn_helper->get_original_url( $image_url );
		}

		return $original_url ?: $image_url;
	}

	/**
	 * Builds the full CDN status props for React, including the 'disabled' state.
	 * CDN_Status::to_react_props() cannot determine 'disabled' on its own.
	 *
	 * @return array
	 */
	private function get_cdn_status_react_props() {
		return $this->cdn_settings_to_react_props( $this->cdn_helper->get_cdn_status_setting() );
	}

	/**
	 * Convert CDN status to React props.
	 * @param mixed $cdn_status
	 * @return array
	 */
	private function cdn_settings_to_react_props( $cdn_status ) {
		$cdn_status_dto        = CDN_Status::from_setting( $cdn_status );
		$props                 = $cdn_status_dto ? $cdn_status_dto->to_react_props() : array();
		$props['statusString'] = $this->cdn_helper->get_cdn_status_string();

		return $props;
	}

	/**
	 * Localize CDN settings for React.
	 *
	 * @param array $localize Current localize data.
	 *
	 * @return array
	 */
	public function localize_cdn_script_data( $localize ) {
		if ( ! is_admin() ) {
			return $localize;
		}

		$localize['cdnSettings'] = CDN_Settings_DTO::to_react_props( $this->cdn_helper->get_cdn_options() );
		$localize['cdnStatus']   = $this->get_cdn_status_react_props();

		return $localize;
	}

	/**
	 * Handle CDN settings sync via unified endpoint.
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
		// Only handle CDN context
		if ( 'cdn' !== $context ) {
			return $saved_settings;
		}

		// Convert React camelCase to PHP format using DTO
		$db_settings = CDN_Settings_DTO::from_react_props( $settings );

		// Separate main CDN settings from advanced settings.
		$cdn_fields        = $this->settings->get_cdn_fields();
		$main_settings     = array();
		$advanced_settings = array();

		foreach ( $db_settings as $key => $value ) {
			if ( in_array( $key, $cdn_fields, true ) ) {
				// These go to wp-smush-settings.
				$main_settings[ $key ] = $value;
			} else {
				// These go to wp-smush-cdn-advanced-settings (e.g., excluded-keywords).
				$advanced_settings[ $key ] = $value;
			}
		}

		// Save main CDN settings to wp-smush-settings.
		foreach ( $main_settings as $key => $value ) {
			$this->settings->set( $key, $value );
		}

		// Save advanced settings to wp-smush-cdn-advanced-settings.
		if ( ! empty( $advanced_settings ) ) {
			$current_advanced_settings = $this->cdn_helper->get_cdn_advanced_settings();
			$updated_advanced_settings = array_merge( $current_advanced_settings, $advanced_settings );
			$this->settings->set_setting( 'wp-smush-cdn-advanced-settings', $updated_advanced_settings );
		}

		// Return transformed data.
		return CDN_Settings_DTO::to_react_props( $this->cdn_helper->get_cdn_options() );
	}
}