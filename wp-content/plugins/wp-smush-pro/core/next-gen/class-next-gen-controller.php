<?php

namespace Smush\Core\Next_Gen;

use Smush\Core\Avif\Avif_Configuration;
use Smush\Core\Controller;
use Smush\Core\Settings;
use Smush\Core\Webp\Webp_Configuration;
use WP_Smush;

class Next_Gen_Controller extends Controller {
	private static $delete_old_images_cron_hook = 'wp_smush_next_gen_delete_old_images';
	private static $old_images_retention_days = 7;

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var Next_Gen_Manager
	 */
	private $next_gen_manager;

	public function __construct() {
		$this->settings         = Settings::get_instance();
		$this->next_gen_manager = Next_Gen_Manager::get_instance();

		$this->register_filter( 'wp_smush_localize_ui_script_data', array( $this, 'localize_next_gen_script_data' ) );
		$this->register_filter( 'wp_smush_sync_settings', array( $this, 'handle_settings_sync' ), 10, 3 );
		$this->register_action( 'wp_smush_webp_status_changed', array( $this, 'maybe_update_previously_active_format_key_on_webp_status_changed' ) );
		$this->register_action( 'wp_smush_avif_status_changed', array( $this, 'maybe_update_previously_active_format_key_on_avif_status_changed' ) );
		$this->register_action( 'wp_smush_next_gen_after_format_switch', array( $this, 'schedule_delete_old_next_gen_images_cron' ), 10, 2 );
		$this->register_action( self::$delete_old_images_cron_hook, array( $this, 'cron_delete_old_next_gen_images' ) );
	}

	public function maybe_update_previously_active_format_key_on_webp_status_changed() {
		if ( $this->settings->is_webp_module_active() ) {
			return;
		}

		$this->next_gen_manager->save_previously_active_format_key( Webp_Configuration::get_format_key() );
	}

	public function maybe_update_previously_active_format_key_on_avif_status_changed() {
		if ( $this->settings->is_avif_module_active() ) {
			return;
		}

		$this->next_gen_manager->save_previously_active_format_key( Avif_Configuration::get_format_key() );
	}

	public function schedule_delete_old_next_gen_images_cron( $new_format_key, $old_format_key ) {
		wp_unschedule_hook( self::$delete_old_images_cron_hook );

		// Schedules a new event.
		$cron_time = time() + DAY_IN_SECONDS * self::$old_images_retention_days;
		wp_schedule_single_event( $cron_time, self::$delete_old_images_cron_hook, array( $old_format_key ) );
	}

	public function cron_delete_old_next_gen_images( $format_key ) {
		if ( ! wp_doing_cron() ) {
			return;
		}

		$configuration = $this->next_gen_manager->get_format_configuration( $format_key );
		if ( $configuration->is_activated() ) {
			return;
		}

		$configuration->delete_all_next_gen_files();
	}

	/**
	 * Get delete_old_images_cron_hook.
	 *
	 * @return string
	 */
	public static function get_delete_old_images_cron_hook() {
		return self::$delete_old_images_cron_hook;
	}


	/**
	 * Get old_images_retention_days.
	 *
	 * @return int
	 */
	public static function get_old_images_retention_days() {
		return self::$old_images_retention_days;
	}

	/**
	 * Localize next-gen settings for React.
	 *
	 * @param array $localize Current localize data.
	 *
	 * @return array
	 */
	public function localize_next_gen_script_data( $localize ) {
		if ( ! is_admin() ) {
			return $localize;
		}

		// Get next-gen field settings (WebP + AVIF).
		$next_gen_settings = array();
		foreach ( $this->settings->get_next_gen_fields() as $field ) {
			$next_gen_settings[ $field ] = $this->settings->get( $field );
		}

		$next_gen_settings                = Next_Gen_Settings_DTO::to_react_props( $next_gen_settings );
		$next_gen_settings['setupWizard'] = $this->setup_wizard_script_data();

		// Transform using DTO.
		$localize['nextgenSettings'] = $next_gen_settings;

		return $localize;
	}

	/**
	 * Get setup wizard script data.
	 *
	 * @return array
	 */
	private function setup_wizard_script_data() {
		$current_nextgen_configuration = $this->next_gen_manager->get_active_format_configuration();
		if (
			! $current_nextgen_configuration->should_show_wizard()
			// AVIF is not required setup.
			|| ! $current_nextgen_configuration->support_server_configuration()
		) {
			return array(
				'showSetupWizard' => false,
				'isConfigured'     => $current_nextgen_configuration->is_configured(),
			);
		}

		// Only Webp configuration required configured.
		$server_configuration = $current_nextgen_configuration->server_configuration();

		return array(
			'showSetupWizard' => true,
			'isConfigured'     => false,
			'detectedServer'  => $server_configuration->get_server_type(),
			'apacheRules'     => $server_configuration->get_apache_code(),
			'nginxRules'      => $server_configuration->get_nginx_code(),
			'startStep'       => ! $server_configuration->is_configured() ? 1 : 3,
		);
	}

	/**
	 * Handle next-gen settings sync via unified endpoint.
	 *
	 * @param array|null $saved_settings Saved settings from previous filter, or null.
	 * @param array      $settings       Incoming settings from React (camelCase).
	 * @param string     $context        Context identifier.
	 *
	 * @return array|null Saved settings array if context matches, otherwise pass through.
	 *
	 * @since 3.25.0
	 */
	public function handle_settings_sync( $saved_settings, $settings, $context ) {
		// Only handle nextgen context
		if ( 'nextgen' !== $context ) {
			return $saved_settings;
		}

		// Convert React camelCase to PHP format using DTO
		$db_settings = Next_Gen_Settings_DTO::from_react_props( $settings );
		if ( isset( $db_settings['webp_mod'] ) || isset( $db_settings['avif_mod'] )	) {
			$is_nextgen_activated = ! empty( $db_settings['webp_mod'] ) || ! empty( $db_settings['avif_mod'] );
			if ( $is_nextgen_activated ) {
				$active_format_key = ! empty( $db_settings['webp_mod'] ) ? 'webp' : 'avif';
				$this->next_gen_manager->activate_format( $active_format_key );
			} else {
				$this->next_gen_manager->deactivate();
			}
		}

		if ( isset( $db_settings['webp_direct_conversion'] ) ) {
			Webp_Configuration::get_instance()->switch_method( $db_settings['webp_direct_conversion'] ? Webp_Configuration::get_direct_conversion_method_key() : 'server_conversion' );
		}

		// Get all next-gen fields from settings
		$all_nextgen_fields = $this->settings->get_next_gen_fields();

		// Update only the changed fields in main settings
		foreach ( $db_settings as $key => $value ) {
			if ( in_array( $key, $all_nextgen_fields, true ) ) {
				$this->settings->set( $key, $value );
			}
		}

		// Get updated next-gen settings
		$updated_settings = array();
		foreach ( $all_nextgen_fields as $field ) {
			$updated_settings[ $field ] = $this->settings->get( $field );
		}

		// Update server configuration.
		$this->update_server_configuration( $updated_settings );

		// Return transformed data
		return Next_Gen_Settings_DTO::to_react_props( $updated_settings );
	}

	/**
	 * Update the server configuration base on new settings.
	 *
	 * @param array $updated_settings Updated settings.
	 */
	private function update_server_configuration( $updated_settings ) {
		$webp_activated            = ! empty( $updated_settings['webp_mod'] );
		$direct_conversion_enabled = ! empty( $updated_settings['webp_direct_conversion'] );
		$webp_configuration         = Webp_Configuration::get_instance();
		$server_configuration       = $webp_configuration->server_configuration();
		if ( $webp_activated && ! $direct_conversion_enabled ) {
			$server_configuration->enable();
		} else {
			$server_configuration->disable();
		}
	}
}