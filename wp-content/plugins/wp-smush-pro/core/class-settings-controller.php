<?php
/**
 * Settings Controller
 *
 * Handles AJAX endpoints for main site settings.
 *
 * @package Smush\Core
 * @since 3.25.0
 */

namespace Smush\Core;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Settings_Controller
 *
 * Controller for main site settings AJAX operations.
 *
 * @since 3.25.0
 */
class Settings_Controller extends Controller {

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings = Settings::get_instance();

		// Hook into unified settings sync filter
		$this->register_filter( 'wp_smush_sync_settings', array( $this, 'handle_settings_sync' ), 10, 3 );
		$this->register_filter( 'wp_smush_localize_ui_script_data', array( $this, 'add_settings_to_localized_data' ) );
	}

	/**
	 * Handle main site settings sync via unified endpoint.
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
		// Only handle site context
		if ( 'site' !== $context ) {
			return $saved_settings;
		}

		// Convert React camelCase to PHP format using DTO
		$db_settings = Settings_DTO::from_react_props( $settings );

		// Handle image_sizes separately — stored in its own option, not wp-smush-settings.
		if ( array_key_exists( 'image_sizes', $db_settings ) ) {
			$image_sizes = $db_settings['image_sizes'];
			unset( $db_settings['image_sizes'] );

			if ( ! is_array( $image_sizes ) ) {
				// Non-array values represent "all selected": delete the option so all sizes are processed.
				$this->settings->delete_setting( 'wp-smush-image_sizes' );
			} else {
				// Keep empty array as-is to represent "none selected".
				$image_sizes = array_values( array_filter( array_map( 'sanitize_text_field', $image_sizes ) ) );
				$this->settings->set_setting( 'wp-smush-image_sizes', $image_sizes );
			}
		}

		// Handle resize_sizes separately — stored in its own option, not wp-smush-settings.
		if ( array_key_exists( 'resize_sizes', $db_settings ) ) {
			$resize_sizes = $db_settings['resize_sizes'];
			unset( $db_settings['resize_sizes'] );

			if ( is_array( $resize_sizes ) ) {
				$sanitized = array(
					'width'  => isset( $resize_sizes['width'] )  ? (int) $resize_sizes['width']  : 0,
					'height' => isset( $resize_sizes['height'] ) ? (int) $resize_sizes['height'] : 0,
				);
				$this->settings->set_setting( 'wp-smush-resize_sizes', $sanitized );
			}
		}

		// Update each remaining setting individually
		foreach ( $db_settings as $key => $value ) {
			$this->settings->set( $key, $value );
		}

		// Get the updated settings
		$updated_settings = array();
		foreach ( array_keys( $db_settings ) as $key ) {
			$updated_settings[ $key ] = $this->settings->get( $key );
		}

		// Include the persisted image sizes in the response
		$saved_image_sizes               = $this->settings->get_setting( 'wp-smush-image_sizes' );
		$updated_settings['image_sizes'] = is_array( $saved_image_sizes ) ? $saved_image_sizes : false;

		// Include the persisted resize sizes in the response
		$saved_resize_sizes               = $this->settings->get_setting( 'wp-smush-resize_sizes' );
		$updated_settings['resize_sizes'] = is_array( $saved_resize_sizes ) ? $saved_resize_sizes : array( 'width' => 0, 'height' => 0 );

		// Return transformed data
		return Settings_DTO::to_react_props( $updated_settings );
	}

	public function add_settings_to_localized_data( $data ) {
		$raw_settings = Settings::get_instance()->get_site_settings();

		// Merge the separately-stored image sizes into siteSettings.
		$image_sizes                 = Settings::get_instance()->get_setting( 'wp-smush-image_sizes' );
		$raw_settings['image_sizes'] = is_array( $image_sizes ) ? $image_sizes : false;

		// Merge the separately-stored resize sizes into siteSettings.
		$resize_sizes                  = Settings::get_instance()->get_setting( 'wp-smush-resize_sizes' );
		$raw_settings['resize_sizes']  = is_array( $resize_sizes ) ? $resize_sizes : array( 'width' => 0, 'height' => 0 );

		$data['siteSettings'] = Settings_DTO::to_react_props( $raw_settings );

		// Pass integration installed status to the UI.
		$data['integrationStatus'] = $this->get_integration_status();

		return $data;
	}

	/**
	 * Detect whether each supported integration plugin is installed/active.
	 *
	 * @return array Map of integration key => bool installed.
	 */
	private function get_integration_status() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		global $wp_version;

		// Gutenberg: active when WP 5.0+ and Classic Editor is NOT installed, or the Gutenberg plugin is active.
		$is_wp5 = version_compare( $wp_version, '4.9.9', '>' );
		if ( $is_wp5 ) {
			$gutenberg_installed = ! is_plugin_active( 'classic-editor/classic-editor.php' );
		} else {
			$gutenberg_installed = is_plugin_active( 'gutenberg/gutenberg.php' );
		}

		return array(
			'gutenberg' => $gutenberg_installed,
			'jsBuilder' => defined( 'WPB_VC_VERSION' ) && function_exists( 'vc_disable_frontend' ),
			'gform'     => defined( 'GF_SUPPORTED_WP_VERSION' ) && class_exists( 'GFForms' ),
			's3'        => class_exists( 'Amazon_S3_And_CloudFront' ),
		);
	}
}