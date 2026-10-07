<?php

namespace Smush\Core\Product_Analytics;

use Smush\Core\CDN\CDN_Helper;
use Smush\Core\Next_Gen\Next_Gen_Manager;
use Smush\Core\Settings;
use Smush\Core\Stats\Global_Stats;
use Smush\Core\Webp\Webp_Configuration;
use WP_Smush;

class Product_Analytics_Controller_Pro extends Product_Analytics_Controller {
	/**
	 * @var Next_Gen_Manager
	 */
	private $next_gen_manager;

	public function __construct() {
		parent::__construct();
		$this->next_gen_manager = Next_Gen_Manager::get_instance();

		add_action( 'wp_smush_settings_updated', array( $this, 'track_toggle_next_gen_fallback' ), 10, 2 );
		if ( $this->is_usage_tracking_enabled() ) {
			add_action( 'wp_smush_webp_method_changed', array( $this, 'track_webp_method_changed' ) );
			add_action( 'wp_smush_webp_status_changed', array( $this, 'track_next_gen_status_changed' ) );
			add_action( 'wp_smush_avif_status_changed', array( $this, 'track_next_gen_status_changed' ) );
			add_action(
				'wp_smush_after_delete_all_webp_files',
				array(
					$this,
					'track_deleting_all_next_gen_files',
				)
			);
			add_action(
				'wp_smush_after_delete_all_avif_files',
				array(
					$this,
					'track_deleting_all_next_gen_files',
				)
			);

			add_action( 'shutdown', array( $this, 'maybe_track_next_gen_format_changed' ) );
		}
	}

	protected function track_webp_mod_feature_toggle( $setting_value ) {
		if ( $this->is_switching_next_gen_format() ) {
			return;
		}

		return $this->track_feature_toggle( $setting_value, 'Next-Gen' );
	}

	protected function track_avif_mod_feature_toggle( $setting_value ) {
		if ( $this->is_switching_next_gen_format() ) {
			return;
		}

		return $this->track_feature_toggle( $setting_value, 'Next-Gen' );
	}

	protected function is_switching_next_gen_format() {
		return did_action( 'wp_smush_next_gen_before_format_switch' );
	}

	protected function track_cdn_feature_toggle( $setting_value ) {
		return $this->track_feature_toggle( $setting_value, 'CDN' );
	}

	protected function track_preload_images_feature_toggle( $setting_value ) {
		return $this->track_feature_toggle( $setting_value, 'Preload Critical Images' );
	}

	public function track_deleting_all_next_gen_files() {
		$auto_deleting_old_next_gen_files = wp_doing_cron();
		if ( $auto_deleting_old_next_gen_files ) {
			return;
		}

		$next_gen_properties = $this->get_next_gen_properties();
		$this->track(
			'next_gen_updated',
			array_merge(
				$next_gen_properties,
				array(
					'update_type' => 'delete_files',
				)
			)
		);
	}

	public function track_toggle_next_gen_fallback( $old_settings, $settings ) {
		if ( empty( $settings['usage'] ) ) {
			return;
		}

		$webp_activated     = ! empty( $settings['webp_mod'] );
		$avif_activated     = ! empty( $settings['avif_mod'] );
		$next_gen_activated = $webp_activated || $avif_activated;
		// Do not track when Next Gen is not activated.
		if ( ! $next_gen_activated ) {
			return;
		}

		$modified_settings         = $this->remove_unchanged_settings( $old_settings, $settings );
		$next_gen_fallback_changed = isset( $modified_settings['webp_fallback'] ) || isset( $modified_settings['avif_fallback'] );
		// Do not track if both WebP and AVIF fallbacks are not changed.
		if ( ! $next_gen_fallback_changed ) {
			return;
		}

		$webp_fallback_activated = ! empty( $settings['webp_fallback'] );
		$avif_fallback_activated = ! empty( $settings['avif_fallback'] );
		// Do not track if both WebP and AVIF fallbacks have the same status while switching the Next-Gen formats.
		if ( $this->is_switching_next_gen_format() && ( $webp_fallback_activated === $avif_fallback_activated ) ) {
			return;
		}

		$next_gen_fallback_activated = ( $webp_activated && $webp_fallback_activated )
		                               || ( $avif_activated && $avif_fallback_activated );

		$update_type         = $next_gen_fallback_activated ? 'browser_support_on' : 'browser_support_off';
		$next_gen_properties = $this->get_next_gen_properties();
		$next_gen_method     = 'avif_direct';
		if ( $webp_activated ) {
			$direct_conversion_enabled = ! empty( $settings['webp_direct_conversion'] );// WebP method might or might not be changed.
			$next_gen_method           = $direct_conversion_enabled ? 'webp_direct' : 'server_redirect';
		}

		$this->track(
			'next_gen_updated',
			array_merge(
				$next_gen_properties,
				array(
					'update_type' => $update_type,
					'Method'      => $next_gen_method,
				)
			)
		);
	}

	public function track_webp_method_changed() {
		$next_gen_properties = $this->get_next_gen_properties();
		$this->track(
			'next_gen_updated',
			array_merge(
				$next_gen_properties,
				array(
					'update_type' => 'switch_webp_method',
				)
			)
		);
	}

	public function track_next_gen_status_changed() {
		if ( $this->is_switching_next_gen_format() ) {
			return;
		}

		$next_gen_properties = $this->get_next_gen_properties();
		$update_type         = $this->next_gen_manager->is_active() ? 'activate' : 'deactivate';
		$this->track(
			'next_gen_updated',
			array_merge(
				$next_gen_properties,
				array(
					'update_type' => $update_type,
				)
			)
		);
	}

	/**
	 * Note: Uses shutdown action to ensure all new settings are updated.
	 */
	public function maybe_track_next_gen_format_changed() {
		$switched_next_gen_format = did_action( 'wp_smush_next_gen_after_format_switch' );
		if ( ! $switched_next_gen_format ) {
			return;
		}

		$next_gen_properties = $this->get_next_gen_properties();
		$this->track(
			'next_gen_updated',
			array_merge(
				$next_gen_properties,
				array(
					'update_type' => 'switch_next_gen_format',
				)
			)
		);
	}

	private function get_next_gen_referer() {
		$page                   = $this->get_referer_page();
		$webp_configuration     = Webp_Configuration::get_instance();
		$is_user_on_wizard_webp = 'smush-next-gen' === $page
		                          && $webp_configuration->should_show_wizard()
		                          && ! $webp_configuration->direct_conversion_enabled();

		if ( $is_user_on_wizard_webp ) {
			return 'Wizard';
		}

		return $this->identify_referrer();
	}

	private function get_next_gen_properties() {
		$location                    = $this->get_next_gen_referer();
		$active_format_configuration = $this->next_gen_manager->get_active_format_configuration();
		$next_gen_status_notice      = $this->get_next_gen_status_notice();
		$next_gen_method             = 'avif_direct';
		if ( Webp_Configuration::get_format_key() === $active_format_configuration->get_format_key() ) {
			// Directly check webp_direct_conversion option to identify webp method even webp module is disabled.
			$direct_conversion_enabled = $this->settings->get( 'webp_direct_conversion' );
			$next_gen_method           = $direct_conversion_enabled ? 'webp_direct' : 'webp_server';
		}

		return array(
			'Location'      => $location,
			'Method'        => $next_gen_method,
			'status_notice' => $next_gen_status_notice,
		);
	}

	private function get_next_gen_status_notice() {
		if ( ! $this->next_gen_manager->is_active() ) {
			return 'na';
		}

		if ( ! $this->next_gen_manager->is_configured() ) {
			$webp_configuration = Webp_Configuration::get_instance();
			return $webp_configuration->server_configuration()->get_configuration_error_code();
		}

		if ( is_multisite() ) {
			return 'active_subsite';// Activated but required run Bulk Smush on subsites.
		}

		$required_bulk_smush = Global_Stats::get()->is_outdated() || Global_Stats::get()->get_remaining_count() > 0;
		if ( $required_bulk_smush ) {
			return 'active_need_smush';
		}

		$auto_smush_enabled = $this->settings->is_automatic_compression_active();
		if ( $auto_smush_enabled ) {
			return 'active_automatic_enabled';
		}

		return 'active_automatic_disabled';
	}

	protected function maybe_track_cdn_update( $settings ) {
		$cdn_properties      = array();
		$cdn_property_labels = $this->cdn_property_labels();
		foreach ( $settings as $setting_key => $setting_value ) {
			if ( array_key_exists( $setting_key, $cdn_property_labels ) ) {
				$property_label                    = $cdn_property_labels[ $setting_key ];
				$property_value                    = $setting_value ? 'Enabled' : 'Disabled';
				$cdn_properties[ $property_label ] = $property_value;
			}
		}

		if ( isset( $settings[ Settings::get_next_gen_cdn_key() ] ) ) {
			$cdn_next_gen_conversions_mode = $this->settings->sanitize_cdn_next_gen_conversion_mode( $settings[ Settings::get_next_gen_cdn_key() ] );
			$cdn_next_gen_conversions      = array(
				Settings::get_none_cdn_mode() => 'None',
				Settings::get_webp_cdn_mode() => 'WebP',
				Settings::get_avif_cdn_mode() => 'AVIF',
			);
			if ( ! isset( $cdn_next_gen_conversions[ $cdn_next_gen_conversions_mode ] ) ) {
				$cdn_next_gen_conversions_mode = Settings::get_none_cdn_mode();
			}

			$cdn_properties['Next-Gen Conversions'] = $cdn_next_gen_conversions[ $cdn_next_gen_conversions_mode ];
		}

		if ( $cdn_properties ) {
			$this->track( 'CDN Updated', $cdn_properties );

			return true;
		}

		return false;
	}

	private function cdn_property_labels() {
		return array(
			'background_images' => 'Background Images',
			'cdn_dynamic_sizes' => 'Dynamic Image Sizing',
			'rest_api_support'  => 'Rest API',
		);
	}

	protected function get_background_optimization_status() {
		$bg_optimization = WP_Smush::get_instance()->core()->mod->bg_optimization;
		return $bg_optimization->is_background_enabled() ? 'Enabled' : 'Disabled';
	}

	protected function get_active_pro_features() {
		$lossy_level           = $this->settings->get_lossy_level_setting();
		$cdn_module_activated  = CDN_Helper::get_instance()->is_cdn_active();
		$webp_module_activated = ! $cdn_module_activated && $this->settings->is_webp_module_active();
		$avif_module_activated = ! $cdn_module_activated && $this->settings->is_avif_module_active();
		$webp_direct_activated = $webp_module_activated && $this->settings->is_webp_direct_conversion_active();
		$webp_server_activated = $webp_module_activated && ! $webp_direct_activated;
		return array(
			'smush_ultra'     => Settings::get_level_ultra_lossy() === $lossy_level,
			'cdn'             => $cdn_module_activated,
			'avif'            => $avif_module_activated,
			'webp_direct'     => $webp_direct_activated,
			'webp_server'     => $webp_server_activated,
			's3_offload'      => $this->settings->is_s3_active(),
			'nextgen_gallery' => $this->settings->get( 'nextgen' ),
			'preload_images'  => $this->settings->is_lcp_preload_enabled(),
		);
	}

	/**
	 * Track lazy load updated event on toggle auto resizing.
	 *
	 * @param bool $setting_value Setting value.
	 *
	 * @return void
	 */
	protected function track_auto_resizing_feature_toggle( $setting_value ) {
		if ( ! $this->is_syncing_settings() ) {
			return;
		}
		$lazyload_settings = $this->settings->get_setting( 'wp-smush-lazy_load', array() );
		$this->track_lazy_load_updated(
			array(
				'update_type'          => 'modify',
				'modified_settings'     => 'na',
				'auto_resizing_status' => $setting_value ? 'Enabled' : 'Disabled',
			),
			$lazyload_settings
		);
	}


	/**
	 * Track lazy load updated event on toggle auto resizing.
	 *
	 * @param bool $setting_value Setting value.
	 *
	 * @return void
	 */
	protected function track_image_dimensions_feature_toggle( $setting_value ) {
		if ( ! $this->is_syncing_settings() ) {
			return;
		}
		$lazyload_settings = $this->settings->get_setting( 'wp-smush-lazy_load', array() );
		$this->track_lazy_load_updated(
			array(
				'update_type'             => 'modify',
				'modified_settings'        => 'na',
				'image_dimensions_status' => $setting_value ? 'Enabled' : 'Disabled',
			),
			$lazyload_settings
		);
	}
}