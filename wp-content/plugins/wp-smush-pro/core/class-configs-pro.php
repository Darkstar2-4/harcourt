<?php

namespace Smush\Core;

use Smush\Core\CDN\CDN_Controller;
use Smush\Core\Membership\Membership;
use Smush\Core\Next_Gen\Next_Gen_Manager;
use WP_Smush;

class Configs_Pro extends Configs {
	/**
	 * Get the list of placeholder features.
	 *
	 * @return array
	 */
	protected function get_placeholder_features() {
		if ( Membership::get_instance()->is_pro() ) {
			return array();
		}

		return parent::get_placeholder_features();
	}

	/**
	 * When applying a config in the pro version we need to configure pro modules and disallow enabling pro features if membership has expired.
	 */
	public function apply_config( $config, $config_name = '' ) {
		if ( ! Membership::get_instance()->is_pro() ) {
			return parent::apply_config( $config, $config_name );
		}

		$settings_handler = Settings::get_instance();
		$sanitized_config = $this->sanitize_config( $config );

		if ( ! empty( $sanitized_config['settings'] ) ) {
			$stored_settings = $settings_handler->get_site_settings();

			$new_settings = array_intersect_key( $sanitized_config['settings'], $stored_settings );

			if ( $new_settings ) {
				if ( isset( $new_settings['webp_mod'] ) || isset( $new_settings['avif_mod'] ) ) {
					$direct_conversion_enabled = ! empty( $new_settings['webp_direct_conversion'] );
					$settings_handler->set( 'webp_direct_conversion', $direct_conversion_enabled );

					$webp_activated   = ! empty( $new_settings['webp_mod'] );
					$avif_activated   = ! empty( $new_settings['avif_mod'] );
					$next_gen_manager = Next_Gen_Manager::get_instance();

					if ( $webp_activated || $avif_activated ) {
						$activated_format = $webp_activated ? 'webp' : 'avif';
						$next_gen_manager->activate_format( $activated_format );
					} else {
						$next_gen_manager->deactivate();
					}
				}

				// Update the CDN status for CDN changes.
				if ( isset( $new_settings['cdn'] ) && $new_settings['cdn'] !== $stored_settings['cdn'] ) {
					CDN_Controller::get_instance()->toggle_cdn( $new_settings['cdn'] );
				}
			}
		}

		parent::apply_config( $config, $config_name );
	}

	/**
	 * In the free version, we want to show the pro features as 'inactive' in the display, in pro we need to check first if the feature is active or not and show the correct status.
	 */
	protected function format_boolean_setting_value( $name, $value ) {
		// Display the pro features as 'inactive' for free installs.
		if ( ! Membership::get_instance()->is_pro() && in_array( $name, $this->get_placeholder_features(), true ) ) {
			$value = false;
		}
		return $value ? __( 'Active', 'wp-smushit' ) : __( 'Inactive', 'wp-smushit' );
	}

	/**
	 * In the pro version cdn can be active so we need to show the correct status for cdn
	 */
	protected function format_config_to_display( $config ) {
		$display_array = parent::format_config_to_display( $config );

		if ( ! empty( $config['settings']['cdn'] && Membership::get_instance()->is_pro() ) ) {
			$display_array['cdn'] = $this->get_settings_display_value( $config, Settings::get_instance()->get_cdn_fields() );
		}

		return $display_array;
	}

	/**
	 * In the pro version preload can be active so we need to show the correct status for preload
	 */
	protected function get_lazy_preload_settings_to_display( $config ) {
		$is_preload_images_active = Membership::get_instance()->is_pro() && ! empty( $config['settings']['preload_images'] );
		$is_lazy_load_active      = ! empty( $config['settings']['lazy_load'] );

		if ( ! $is_preload_images_active && ! $is_lazy_load_active ) {
			return __( 'Inactive', 'wp-smushit' );
		}

		$formatted_rows = array();

		$formatted_rows[] = __( 'Lazy Load', 'wp-smushit' ) . ' - ' . $this->format_boolean_setting_value( 'lazy_load', $is_lazy_load_active );
		if ( $is_lazy_load_active ) {
			$formatted_rows = array_merge( $formatted_rows, $this->get_lazy_load_settings_to_display( $config ) );
		}

		$formatted_rows[] = __( 'Preload Critical Images', 'wp-smushit' ) . ' - ' . $this->format_boolean_setting_value( 'preload_images', $is_preload_images_active );

		return $formatted_rows;
	}

	protected function get_next_gen_settings_display_value( $config ) {
		$is_pro       = Membership::get_instance()->is_pro();
		$webp_enabled = $is_pro && ! empty( $config['settings']['webp_mod'] );
		$avif_enabled = $is_pro && ! empty( $config['settings']['avif_mod'] );

		if ( ! $webp_enabled && ! $avif_enabled ) {
			return __( 'Inactive', 'wp-smushit' );
		}

		$next_gen_format           = $avif_enabled ? __( 'AVIF', 'wp-smushit' ) : __( 'WebP', 'wp-smushit' );
		$direct_conversion_enabled = $avif_enabled || ! empty( $config['settings']['webp_direct_conversion'] );
		$transform_mode            = $direct_conversion_enabled ? __( 'Direct Conversion', 'wp-smushit' ) : __( 'Server Configuration', 'wp-smushit' );

		$formatted_rows = array(
			$this->format_config_description( __( 'Next-Gen Formats', 'wp-smushit' ), $next_gen_format ),
			$this->format_config_description( __( 'Transform Mode', 'wp-smushit' ), $transform_mode ),
		);

		if ( $direct_conversion_enabled ) {
			$legacy_browser_support = $avif_enabled && ! empty( $config['settings']['avif_fallback'] )
									  || ( $webp_enabled && ! empty( $config['settings']['webp_fallback'] ) );
			$formatted_rows[]       = $this->format_config_description(
				__( 'Legacy Browser Support', 'wp-smushit' ),
				$legacy_browser_support ? __( 'Active', 'wp-smushit' ) : __( 'Inactive', 'wp-smushit' )
			);
		}

		return $formatted_rows;
	}
}