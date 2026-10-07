<?php
/**
 * Smush installer (update/upgrade procedures): Installer class
 *
 * @package Smush\Core
 * @since 2.8.0
 *
 * @author Anton Vanyukov <anton@incsub.com>
 *
 * @copyright (c) 2018, Incsub (http://incsub.com)
 */

namespace Smush\Core;

use Smush\App\Abstract_Page;
use Smush\Core\CDN\CDN_Controller;
use Smush\Core\Smush\Smusher;
use Smush\Core\Smush\Smusher_Options_Provider;
use WP_Smush;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Installer for handling updates and upgrades of the plugin.
 *
 * @since 2.8.0
 */
class Installer {

	/**
	 * Triggered on Smush deactivation.
	 *
	 * @since 3.1.0
	 */
	public static function smush_deactivated() {
		if ( ! class_exists( '\\Smush\\Core\\Modules\\CDN_Controller' ) ) {
			$cdn_controller_path = __DIR__ . '/cdn/class-cdn-controller.php';
			if ( file_exists( $cdn_controller_path ) ) {
				require_once $cdn_controller_path;
			}
		}

		Cron_Controller::get_instance()->unschedule_cron();
		Settings::get_instance()->delete_setting( 'wp-smush-cdn_status' );

		delete_site_option( 'wp_smush_api_auth' );
	}

	/**
	 * Redirect to Smush page after plugin activation if onboarding wizard has not been completed.
	 *
	 * @since 3.17.0
	 *
	 * @param string $plugin Plugin basename.
	 */
	public static function redirect_to_setup_page( $plugin ) {
		// Check if this is the Smush plugin being activated.
		if ( WP_SMUSH_BASENAME !== $plugin ) {
			return;
		}

		// Check if onboarding wizard has been completed.
		$skip_quick_setup = ! empty( get_option( 'skip-smush-setup' ) );
		if ( $skip_quick_setup ) {
			return;
		}

		// Don't redirect if activating multiple plugins.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}

		// Don't redirect on AJAX, CLI, REST API, or network admin.
		if ( wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_is_serving_rest_request() ) {
			return;
		}

		// Redirect to Smush page.
		wp_safe_redirect( is_network_admin() ? network_admin_url( 'admin.php?page=smush' ) : admin_url( 'admin.php?page=smush' ) );
		exit;
	}

	/**
	 * Check if an existing install or new.
	 *
	 * @since 2.8.0  Moved to this class from wp-smush.php file.
	 */
	public static function smush_activated() {
		if ( ! defined( 'WP_SMUSH_ACTIVATING' ) ) {
			define( 'WP_SMUSH_ACTIVATING', true );
		}

		$version = get_site_option( 'wp-smush-version' );
		self::maybe_mark_as_pre_3_22_site( $version );

		// Cache activated date time.
		$event_name = ! empty( $version ) ? 'plugin_activated' : 'plugin_installed';
		self::cache_event_time( $event_name );

		if ( ! class_exists( '\\Smush\\Core\\Settings' ) ) {
			require_once __DIR__ . '/class-settings.php';
		}

		Settings::get_instance()->initial_default_site_settings();

		// If the version is not saved or if the version is not same as the current version,.
		if ( ! $version || WP_SMUSH_VERSION !== $version ) {
			global $wpdb;
			// Check if there are any existing smush stats.
			$results = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key=%s LIMIT 1",
					'wp-smpro-smush-data'
				)
			); // db call ok; no-cache ok.

			if ( $results || $version ) {
				update_site_option( 'wp-smush-install-type', 'existing' );
			}

			// Create directory smush table.
			self::directory_smush_table();

			// Store the plugin version in db.
			update_site_option( 'wp-smush-version', WP_SMUSH_VERSION );
		}
	}

	/**
	 * Handle plugin upgrades.
	 *
	 * @since 2.8.0
	 */
	public static function upgrade_settings() {
		// Avoid executing this over an over in same thread.
		if ( defined( 'WP_SMUSH_ACTIVATING' ) || ( defined( 'WP_SMUSH_UPGRADING' ) && WP_SMUSH_UPGRADING ) ) {
			return;
		}

		if ( ! class_exists( '\\Smush\\Core\\Settings' ) ) {
			require_once __DIR__ . '/class-settings.php';
		}

		$version = get_site_option( 'wp-smush-version' );

		if ( false === $version ) {
			self::smush_activated();
		} else {
			self::maybe_mark_as_pre_3_22_site( $version );
		}

		if ( false !== $version && WP_SMUSH_VERSION !== $version ) {
			if ( ! defined( 'WP_SMUSH_UPGRADING' ) ) {
				define( 'WP_SMUSH_UPGRADING', true );
			}

			// Cache last updated time.
			self::cache_event_time( 'plugin_upgraded' );

			if ( version_compare( $version, '3.7.0', '<' ) ) {
				self::upgrade_3_7_0();
			}

			if ( version_compare( $version, '3.8.0', '<' ) ) {
				// Delete the flag for hiding the BF modal because it was removed.
				delete_site_option( 'wp-smush-hide_blackfriday_modal' );
			}

			if ( version_compare( $version, '3.8.3', '<' ) ) {
				// Delete this unused setting, leftover from old smush.
				delete_option( 'wp-smush-transparent_png' );
			}

			if ( version_compare( $version, '3.9.5', '<' ) ) {
				delete_site_option( 'wp-smush-show-black-friday' );
			}

			if ( version_compare( $version, '3.9.10', '<' ) ) {
				self::dir_smush_set_primary_key();
			}

			if ( version_compare( $version, '3.10.0', '<' ) ) {
				self::upgrade_3_10_0();
			}

			if ( version_compare( $version, '3.10.3', '<' ) ) {
				self::upgrade_3_10_3();
			}

			if ( version_compare( $version, '3.16.0', '<' ) ) {
				self::regenerate_preset_configs_before_3_16_0();
			} elseif ( version_compare( $version, '3.21.0', '<' ) ) {
				self::regenerate_preset_configs();
			}

			if ( version_compare( $version, '3.21.0', '<' ) ) {
				self::upgrade_3_21_0();
			}

			if ( version_compare( $version, '4.0', '<' ) ) {
				self::upgrade_4_0_0();
			}

			if ( version_compare( $version, '4.2.0', '<' ) ) {
				self::upgrade_4_2_0();
			}

			if ( version_compare( $version, '4.2.1', '<' ) ) {
				self::upgrade_4_2_1();
			}

			if ( version_compare( $version, '4.3.2', '<' ) ) {
				self::upgrade_4_3_2();
			}

			if ( version_compare( $version, '4.0', '>=' ) && version_compare( $version, '4.3.3', '<' ) ) {
				self::dismiss_autosave_intro_for_existing_v4_users();
			}

			if ( version_compare( $version, '4.0', '<' ) ) {
				$hide_new_feature_highlight_modal = apply_filters( 'wpmudev_branding_hide_doc_link', false );
				if ( ! $hide_new_feature_highlight_modal ) {
					// Add the flag to display the new feature background process modal.
					add_site_option( 'wp-smush-show_upgrade_modal', true );
				}

				// Show new feature hotspot.
				// self::set_new_feature_hotspot_flag();
			}

			// Create/upgrade directory smush table.
			self::directory_smush_table();

			// Store the latest plugin version in db.
			update_site_option( 'wp-smush-version', WP_SMUSH_VERSION );

			self::reset_smusher_error_counts();
		}
	}

	/**
	 * Create or upgrade custom table for directory Smush.
	 *
	 * After creating or upgrading the custom table, update the path_hash
	 * column value and structure if upgrading from old version.
	 *
	 * @since 2.9.0
	 */
	public static function directory_smush_table() {
		if ( ! class_exists( '\\Smush\\Core\\Modules\\Abstract_Module' ) ) {
			require_once __DIR__ . '/modules/class-abstract-module.php';
		}

		if ( ! class_exists( '\\Smush\\Core\\Modules\\Dir' ) ) {
			require_once __DIR__ . '/modules/class-dir.php';
		}

		// No need to continue on sub sites.
		if ( ! Modules\Dir::should_continue() ) {
			return;
		}

		// Create a class object, if doesn't exists.
		if ( ! is_object( WP_Smush::get_instance()->core()->mod->dir ) ) {
			WP_Smush::get_instance()->core()->mod->dir = new Modules\Dir();
		}

		// Create/upgrade directory smush table.
		WP_Smush::get_instance()->core()->mod->dir->create_table();
	}

	/**
	 * Set primary key for directory smush table on upgrade to 3.9.10.
	 *
	 * @since 3.9.10
	 */
	private static function dir_smush_set_primary_key() {
		global $wpdb;

		// Only call it after creating table smush_dir_images. If the table doesn't exist, returns.
		if ( ! Modules\Dir::table_exist() ) {
			return;
		}

		// If the table is already set the primary key, return.
		if ( $wpdb->query( $wpdb->prepare( "SHOW INDEXES FROM {$wpdb->base_prefix}smush_dir_images WHERE Key_name = %s;", 'PRIMARY' ) ) ) {
			return;
		}

		// Set column ID as a primary key.
		$wpdb->query( "ALTER TABLE {$wpdb->base_prefix}smush_dir_images ADD PRIMARY KEY (id);" );
	}

	/**
	 * Check if table needs to be created and create if not exists.
	 *
	 * @since 3.8.6
	 */
	public static function maybe_create_table() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		if ( isset( get_current_screen()->id ) && false === strpos( get_current_screen()->id, 'page_smush' ) ) {
			return;
		}

		self::directory_smush_table();
	}

	/**
	 * Upgrade to 3.7.0
	 *
	 * @since 3.7.0
	 */
	private static function upgrade_3_7_0() {
		delete_site_option( 'wp-smush-run_recheck' );

		// Fix the "None" animation in lazy-load options.
		$lazy = Settings::get_instance()->get_setting( 'wp-smush-lazy_load' );

		if ( ! $lazy || ! isset( $lazy['animation'] ) || ! isset( $lazy['animation']['selected'] ) ) {
			return;
		}

		if ( '0' === $lazy['animation']['selected'] ) {
			$lazy['animation']['selected'] = 'none';
			Settings::get_instance()->set_setting( 'wp-smush-lazy_load', $lazy );
		}
	}

	/**
	 * Upgrade to 3.10.0
	 *
	 * @return void
	 * @since 3.10.0
	 */
	private static function upgrade_3_10_0() {
		// Remove unused options.
		delete_site_option( 'wp-smush-hide_pagespeed_suggestion' );
		delete_site_option( 'wp-smush-hide_upgrade_notice' );

		// Rename the default config.
		$stored_configs = get_site_option( 'wp-smush-preset_configs', false );
		if ( is_array( $stored_configs ) && isset( $stored_configs[0] ) && isset( $stored_configs[0]['name'] ) && 'Basic config' === $stored_configs[0]['name'] ) {
			$stored_configs[0]['name'] = __( 'Smush', 'wp-smushit' );
			update_site_option( 'wp-smush-preset_configs', $stored_configs );
		}
	}

	/**
	 * Upgrade to 4.0.0
	 *
	 * @return void
	 * @since 4.0.0
	 */
	private static function upgrade_4_0_0() {
		$settings     = Settings::get_instance();
		$lazy_options = $settings->get_setting( 'wp-smush-lazy_load' );
		if ( ! is_array( $lazy_options ) ) {
			return;
		}

		$changed = self::migrate_lazy_load_placeholder_in_options( $lazy_options );
		$changed = self::migrate_lazy_load_spinner_in_options( $lazy_options ) || $changed;

		if ( $changed ) {
			$settings->set_setting( 'wp-smush-lazy_load', $lazy_options );
		}
	}

	/**
	 * Upgrade to 4.2.0
	 *
	 * Migrates options that moved from PHP-serialized arrays / comma-separated
	 * strings to raw JSON strings so that the new JSON_Record / JSON_Scalar_Array
	 * classes can read them without a full reset.
	 *
	 * @return void
	 * @since 4.2.0
	 */
	private static function upgrade_4_2_0() {
		// Global_Stats option: PHP-serialized array → JSON object.
		self::migrate_serialized_option_to_json( 'wp_smush_global_stats', 'wp_smush_global_stats_json' );

		// Attachment_Id_List options: comma-separated string → JSON array.
		$attachment_id_list_options = array(
			'wp-smush-optimize-list',
			'wp-smush-reoptimize-list',
			'wp-smush-error-items-list',
			'wp-smush-ignored-items-list',
			'wp-smush-animated-items-list',
		);
		foreach ( $attachment_id_list_options as $option_id ) {
			self::migrate_comma_separated_option_to_json_array( $option_id, $option_id . '-json' );
		}
	}

	/**
	 * Upgrade to 4.2.1
	 *
	 * Migrate video thumbnail cache to a bounded JSON_Record-based
	 * implementation.
	 *
	 * @since 4.2.1
	 *
	 * @return void
	 */
	private static function upgrade_4_2_1() {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_flush(); // Clear the object cache to avoid stale data.
			return;
		}

		if ( is_multisite() ) {
			self::for_each_public_site( function() {
				self::flush_video_thumbnail_cache();
			} );
		} else {
			self::flush_video_thumbnail_cache();
		}
	}

	/**
	 * Flush cached video thumbnails and their cache index.
	 *
	 * @since 4.2.1
	 *
	 * @return void
	 */
	private static function flush_video_thumbnail_cache() {
		global $wpdb;

		$pattern = $wpdb->esc_like(
			'_transient_wp-smush-video-thumbnail-'
		) . '%';

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				WHERE option_name LIKE %s",
				$pattern
			)
		);

		delete_option( 'wp-smush-video-thumbnail-cache-index' );

		/*
		* The direct database query bypasses the WordPress options API.
		*/
		wp_cache_delete( 'alloptions', 'options' );
	}

	private static function upgrade_4_3_2() {
		if ( is_multisite() ) {
			self::for_each_public_site( function() {
				self::delete_transparent_metadata();
			} );
		} else {
			self::delete_transparent_metadata();
		}
	}

	private static function delete_transparent_metadata() {
		$transparent_meta_value = 1;
		$delete_all             = true;
		delete_metadata( 'post', null, 'wp-smush-transparent', $transparent_meta_value, $delete_all );
	}

	/**
	 * Read an option whose value was written by update_option() as a
	 * PHP-serialized array and re-save it as a raw JSON string.
	 *
	 * @param string $option_id WP option name.
	 * @param $new_option_id
	 *
	 * @return void
	 */
	private static function migrate_serialized_option_to_json( $option_id, $new_option_id ) {
		$value = get_option( $option_id, null );
		// get_option() calls maybe_unserialize; PHP-serialized array → array.
		if ( null === $value || ! is_array( $value ) ) {
			return;
		}
		update_option( $new_option_id, wp_json_encode( $value ), false );
	}

	/**
	 * Read an option whose value was written as a comma-separated string of
	 * attachment IDs (e.g. "123,456,789") and re-save it as a JSON array
	 * (e.g. [123,456,789]) so that JSON_Scalar_Array can read it.
	 *
	 * @param string $option_id WP option name.
	 * @param $new_option_id
	 *
	 * @return void
	 */
	private static function migrate_comma_separated_option_to_json_array( $option_id, $new_option_id ) {
		$value = get_option( $option_id, null );
		if ( null === $value ) {
			return;
		}

		// If get_option() already returned an array (e.g. PHP-serialized), encode directly.
		if ( is_array( $value ) ) {
			$ids = array_values( array_map( 'intval', $value ) );
			update_option( $new_option_id, wp_json_encode( $ids ), false );
			return;
		}

		$str = (string) $value;

		// Skip if value is already a valid JSON array.
		if ( '' !== $str && '[' === $str[0] ) {
			return;
		}

		if ( '' === trim( $str ) ) {
			update_option( $new_option_id, '[]', false );
			return;
		}

		// Convert "123,456,789" → [123,456,789].
		$ids = array_values( array_filter( array_map( 'intval', explode( ',', $str ) ) ) );
		update_option( $new_option_id, wp_json_encode( $ids ), false );
	}

	/**
	 * Migrate lazy load placeholder settings.
	 *
	 * @param array $lazy_options Lazy load options.
	 *
	 * @return bool True when settings were changed.
	 */
	private static function migrate_lazy_load_placeholder_in_options( &$lazy_options ) {
		$selected_placeholder = isset( $lazy_options['animation']['placeholder']['selected'] ) ? (int) $lazy_options['animation']['placeholder']['selected'] : 1;
		if ( empty( $selected_placeholder ) || 2 !== $selected_placeholder ) {
			return false;
		}

		// Update lazy load settings.
		$lazy_options['animation']['placeholder']['selected'] = 1;
		return true;
	}

	/**
	 * Migrate lazy load spinner settings.
	 *
	 * @param array $lazy_options Lazy load options.
	 *
	 * @return bool True when settings were changed.
	 */
	private static function migrate_lazy_load_spinner_in_options( &$lazy_options ) {
		$selected_spinner = isset( $lazy_options['animation']['spinner']['selected'] ) ? (int) $lazy_options['animation']['spinner']['selected'] : 1;
		if ( empty( $selected_spinner ) || $selected_spinner > 5 ) {
			return false;
		}

		$default_spinner = 1;
		$map_spinner     = array(
			1 => 2,
			3 => 3,
		);
		// Map the selected spinner to the new value.
		$selected_spinner = isset( $map_spinner[ $selected_spinner ] ) ? $map_spinner[ $selected_spinner ] : $default_spinner;

		// Update lazy load settings.
		$lazy_options['animation']['spinner']['selected'] = $selected_spinner;
		return true;
	}

	/**
	 * Upgrade 3.10.3
	 *
	 * @return void
	 * @since 3.10.3
	 */
	private static function upgrade_3_10_3() {
		delete_site_option( 'wp-smush-hide_smush_welcome' );
		// Logger options.
		delete_site_option( 'wdev_logger_wp-smush-pro' );
		delete_site_option( 'wdev_logger_wp-smushit' );
		// Clean old cronjob (missing callback).
		if ( wp_next_scheduled( 'wdev_logger_clear_logs' ) ) {
			wp_clear_scheduled_hook( 'wdev_logger_clear_logs' );
		}
	}

	private static function maybe_mark_as_pre_3_22_site( $version ) {
		if ( ! $version || false !== get_site_option( 'wp_smush_pre_3_22_site' ) ) {
			return;
		}
		if ( version_compare( $version, '3.21.1', '>' ) ) {
			$version = 0;
		}
		update_site_option( 'wp_smush_pre_3_22_site', $version );
	}

	private static function regenerate_preset_configs_before_3_16_0() {
		// Update Smush mode for display on Configs page.
		$stored_configs = get_site_option( 'wp-smush-preset_configs', array() );
		if ( empty( $stored_configs ) || ! is_array( $stored_configs ) ) {
			return;
		}

		$configs_handler = Configs::get_instance();
		$new_settings    = array(
			'background_email' => true,
		);
		foreach ( $stored_configs as $key => $preset_config ) {
			if ( empty( $preset_config['config']['configs']['settings'] ) ) {
				continue;
			}

			$preset_config ['config']['configs']['settings'] = array_merge( $new_settings, $preset_config['config']['configs']['settings'] );
			$preset_config ['config']                        = $configs_handler->sanitize_and_format_configs( $preset_config['config']['configs'] );
			$stored_configs[ $key ]                          = $preset_config;
		}
		update_site_option( 'wp-smush-preset_configs', $stored_configs );
	}

	private static function regenerate_preset_configs() {
		// Regenerate preset configs to update Next-Gen Formats.
		$stored_configs = get_site_option( 'wp-smush-preset_configs', array() );
		if ( empty( $stored_configs ) || ! is_array( $stored_configs ) ) {
			return;
		}

		$configs_handler = Configs::get_instance();
		foreach ( $stored_configs as $key => $preset_config ) {
			if ( empty( $preset_config['config']['configs'] ) ) {
				continue;
			}

			$preset_config ['config'] = $configs_handler->sanitize_and_format_configs( $preset_config['config']['configs'] );
			$stored_configs[ $key ]   = $preset_config;
		}

		update_site_option( 'wp-smush-preset_configs', $stored_configs );
	}

	private static function upgrade_3_21_0() {
		self::migrate_auto_resize_to_new_settings();
		self::migrate_auto_resize_to_new_settings_for_sub_sites();
	}

	private static function migrate_auto_resize_to_new_settings_for_sub_sites() {
		if ( ! is_multisite() ) {
			return;
		}

		self::for_each_public_site( function() {
			self::migrate_auto_resize_to_new_settings();
		} );
	}

	private static function migrate_auto_resize_to_new_settings() {
		$settings                = Settings::get_instance();
		$is_auto_resizing_active = $settings->get( 'auto_resize' );
		if ( ! $is_auto_resizing_active ) {
			return;
		}
		$settings->set( 'auto_resizing', $is_auto_resizing_active );
		$settings->set( 'cdn_dynamic_sizes', $is_auto_resizing_active );
		$settings->delete( 'auto_resize' );
	}

	private static function cache_event_time( $event ) {
		$option_key            = 'wp_smush_event_times';
		$event_times           = get_site_option( $option_key, array() );
		$event_times[ $event ] = time();
		update_site_option( $option_key, $event_times );
	}

	/**
	 * @return void
	 */
	private static function reset_smusher_error_counts() {
		$smusher_options = ( new Smusher_Options_Provider() )->get_options();
		( new Smusher( $smusher_options ) )->reset_error_counts();
	}

	private static function set_new_feature_hotspot_flag() {
		add_option( 'wp-smush-show-new-feature-hotspot', true );
		self::set_new_feature_hotspot_flag_for_sub_sites();
	}

	private static function set_new_feature_hotspot_flag_for_sub_sites() {
		if ( ! is_multisite() ) {
			return;
		}

		self::for_each_public_site( function() {
			add_option( 'wp-smush-show-new-feature-hotspot', true );
		} );
	}

	/**
	 * Dismiss autosave intro for users already on v4 before this feature lands.
	 *
	 * @return void
	 */
	private static function dismiss_autosave_intro_for_existing_v4_users() {
		if ( is_multisite() ) {
			self::for_each_public_site(
				function () {
					self::set_dismissed_notice( 'autosave_intro_v4' );
				}
			);
			return;
		}

		self::set_dismissed_notice( 'autosave_intro_v4' );
	}

	/**
	 * Mark a notice as dismissed in the existing dismissed notices option.
	 *
	 * @param string $notice_key Notice key.
	 *
	 * @return void
	 */
	private static function set_dismissed_notice( $notice_key ) {
		$option_id         = 'wp-smush-dismissed-notices';
		$dismissed_notices = get_option( $option_id, array() );
		$dismissed_notices = is_array( $dismissed_notices ) ? $dismissed_notices : array();

		if ( ! empty( $dismissed_notices[ $notice_key ] ) ) {
			return;
		}

		$dismissed_notices[ $notice_key ] = true;
		update_option( $option_id, $dismissed_notices );
	}

	private static function for_each_public_site( $callback ) {
		if ( ! is_multisite() ) {
			return;
		}

		$site_args = array(
			'fields' => 'ids',
			'public' => 1,
			'number' => 250, // Limit to 250 sites to avoid performance issues.
		);

		$site_ids = get_sites( $site_args );
		if ( empty( $site_ids ) ) {
			return;
		}

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			call_user_func( $callback );
			restore_current_blog();
		}
	}
}
