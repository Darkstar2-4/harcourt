<?php
/**
 * Plugin Name: WPMU DEV Dashboard
 * Plugin URI: https://wpmudev.com/project/wpmu-dev-dashboard/
 * Description: Brings the powers of WPMU DEV directly to you. It will revolutionize how you use WordPress. Activate now!
 * Author: WPMU DEV
 * Version: 5.0.2
 * Author URI: https://wpmudev.com/
 * Text Domain: wpmudev
 * Domain Path: /language
 * Network: true
 * WDP ID: 119
 * Requires at least: 6.4
 * Requires PHP: 7.4
 *
 * @package WPMUDEV_Dashboard
 */

/*
Copyright 2007-2018 Incsub (http://incsub.com)
Author - Aaron Edwards
Contributors - Philipp Stracker, Victor Ivanov, Vladislav Bailovic, Jeffri H, Marko Miljus

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License (Version 2 - GPLv2) as published by
the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/


if ( ! class_exists( 'WPMUDEV_Dashboard' ) ) {
	/**
	 * The main Dashboard class that behaves as an interface to the other Dashboard
	 * classes.
	 */
	class WPMUDEV_Dashboard {

		/**
		 * The current plugin version. Must match the plugin header.
		 *
		 * @var string (Version number)
		 */
		public static string $version = '5.0.2';

		/**
		 * The current plugin base file name.
		 *
		 * @var string $file File name.
		 */
		public static string $basename;

		/**
		 * Holds the API module.
		 * Handles all the remote calls to the WPMUDEV Server.
		 *
		 * @since 4.0.0
		 * @var   ?WPMUDEV_Dashboard_Api
		 */
		public static ?WPMUDEV_Dashboard_Api $api = null;

		/**
		 * Holds the Remote module.
		 * Handles all the Hub calls from the WPMUDEV Servers.
		 *
		 * @since 4.0.0
		 * @var   ?WPMUDEV_Dashboard_Remote
		 */
		public static ?WPMUDEV_Dashboard_Remote $remote = null;

		/**
		 * Holds the settings module.
		 *
		 * Handles all local things like storing/fetching settings.
		 *
		 * @since 4.11.10
		 * @var ?WPMUDEV_Dashboard_Settings
		 */
		public static ?WPMUDEV_Dashboard_Settings $settings = null;

		/**
		 * Holds the Site module.
		 *
		 * @since 4.0.0
		 * @var   ?WPMUDEV_Dashboard_Site
		 */
		public static ?WPMUDEV_Dashboard_Site $site = null;

		/**
		 * Holds the ajax module.
		 *
		 * @since 4.11.6
		 * @var   ?WPMUDEV_Dashboard_Ajax
		 */
		public static ?WPMUDEV_Dashboard_Ajax $ajax = null;

		/**
		 * Holds the UI module.
		 * Handles all the UI tasks, like displaying a specific Dashboard page.
		 *
		 * @since 4.0.0
		 * @var   ?WPMUDEV_Dashboard_Ui
		 */
		public static ?WPMUDEV_Dashboard_Ui $ui = null;

		/**
		 * Holds the Upgrader module.
		 * Handles all upgrade/installation relevant tasks.
		 *
		 * @since 4.1.0
		 * @var   ?WPMUDEV_Dashboard_Upgrader
		 */
		public static ?WPMUDEV_Dashboard_Upgrader $upgrader = null;

		/**
		 * Whitelabel functionality class.
		 *
		 * @since 4.11.1
		 * @var ?WPMUDEV_Dashboard_Whitelabel
		 */
		public static ?WPMUDEV_Dashboard_Whitelabel $whitelabel = null;

		/**
		 * Utility functionality class.
		 *
		 * @since 4.11.3
		 * @var ?WPMUDEV_Dashboard_Utils
		 */
		public static ?WPMUDEV_Dashboard_Utils $utils = null;

		/**
		 * Compatibility functionality class.
		 *
		 * @since 4.11.3
		 * @var ?WPMUDEV_Dashboard_Compatibility
		 */
		public static ?WPMUDEV_Dashboard_Compatibility $compatibility = null;

		/**
		 * Creates and returns the WPMUDEV Dashboard object.
		 * We'll have only one of those ;)
		 *
		 * Important: This function must be called BEFORE the plugins_loaded hook!
		 *
		 * @since  4.0.0
		 * @return WPMUDEV_Dashboard
		 */
		public static function instance(): self {
			static $inst = null;

			if ( null === $inst ) {
				$inst = new WPMUDEV_Dashboard();
			}

			return $inst;
		}

		/**
		 * The singleton constructor will initialize the modules.
		 *
		 * Important: This function must be called BEFORE the plugins_loaded hook!
		 *
		 * @since 1.0.0
		 */
		private function __construct() {
			// Plugin base name.
			self::$basename = plugin_basename( __FILE__ );

			/**
			 * Fired before initializing the Dashboard.
			 *
			 * @since 5.0.0
			 */
			do_action( 'wpmudev_dashboard_before_init' );

			require_once 'includes/class-wpmudev-dashboard-settings.php';
			require_once 'includes/class-wpmudev-dashboard-site.php';
			require_once 'includes/class-wpmudev-dashboard-ajax.php';
			require_once 'includes/class-wpmudev-dashboard-api.php';
			require_once 'includes/class-wpmudev-dashboard-remote.php';
			require_once 'includes/class-wpmudev-dashboard-sui-page-urls.php';
			require_once 'includes/class-wpmudev-dashboard-ui.php';
			require_once 'includes/class-wpmudev-dashboard-upgrader.php';
			require_once 'includes/class-wpmudev-dashboard-whitelabel.php';
			require_once 'includes/class-wpmudev-dashboard-utils.php';
			require_once 'includes/class-wpmudev-dashboard-compatibility.php';
			require_once 'includes/class-wpmudev-dashboard-special-upgrader.php';
			// Endpoints.
			require_once 'includes/endpoints/class-endpoint.php';
			require_once 'includes/endpoints/class-auth.php';
			require_once 'includes/endpoints/class-settings.php';
			require_once 'includes/endpoints/class-plugins.php';
			require_once 'includes/endpoints/class-free-plugins.php';
			require_once 'includes/endpoints/class-analytics.php';
			require_once 'includes/endpoints/class-data.php';
			require_once 'includes/endpoints/class-support.php';
			require_once 'includes/endpoints/class-translations.php';

			self::$settings      = new WPMUDEV_Dashboard_Settings();
			self::$utils         = new WPMUDEV_Dashboard_Utils();
			self::$site          = new WPMUDEV_Dashboard_Site( __FILE__ );
			self::$ajax          = new WPMUDEV_Dashboard_Ajax();
			self::$api           = new WPMUDEV_Dashboard_Api();
			self::$remote        = new WPMUDEV_Dashboard_Remote();
			self::$upgrader      = new WPMUDEV_Dashboard_Upgrader();
			self::$whitelabel    = new WPMUDEV_Dashboard_Whitelabel();
			self::$compatibility = new WPMUDEV_Dashboard_Compatibility();
			// Endpoints.
			new \WPMUDEV\Dashboard\Endpoints\Auth();
			new \WPMUDEV\Dashboard\Endpoints\Settings();
			new \WPMUDEV\Dashboard\Endpoints\Plugins();
			new \WPMUDEV\Dashboard\Endpoints\Free_Plugins();
			new \WPMUDEV\Dashboard\Endpoints\Analytics();
			new \WPMUDEV\Dashboard\Endpoints\Data();
			new \WPMUDEV\Dashboard\Endpoints\Support();
			new \WPMUDEV\Dashboard\Endpoints\Translations();

			/*
			 * The UI module sets up all the WP hooks when it is created.
			 * So it should stay the last module to create, so it can access the
			 * other modules already in the constructor.
			 */
			self::$ui = new WPMUDEV_Dashboard_Ui();

			// Register the plugin activation hook.
			register_activation_hook( __FILE__, array( $this, 'activate_plugin' ) );

			// Register the plugin deactivation hook.
			register_deactivation_hook( __FILE__, array( $this, 'deactivate_plugin' ) );

			add_action(
				'plugins_loaded',
				function () {
					// Get db version.
					$version = self::$settings->get( 'version', 'general' );
					// Only for v4.11.10.
					if ( empty( $version ) ) {
						// Try to get old structured version number.
						$version = self::$settings->get( 'version' );
					}

					// If existing version is not same, upgrade.
					if ( ! empty( $version ) && version_compare( $version, self::$version, '<' ) ) {
						$this->upgrade_plugin( $version );
					}
				}
			);

			/**
			 * Custom code can be executed after Dashboard is initialized with the
			 * default settings.
			 *
			 * @since  4.0.0
			 *
			 * @param WPMUDEV_Dashboard $instance The initialized dashboard object.
			 */
			do_action( 'wpmudev_dashboard_init', $this );
		}

		/**
		 * Run code on plugin activation.
		 *
		 * @since    1.0.0
		 * @internal Action hook
		 */
		public function activate_plugin() {
			global $current_user;

			// Register the plugin uninstall hook.
			register_uninstall_hook( __FILE__, array( 'WPMUDEV_Dashboard', 'uninstall_plugin' ) );

			// If first time activation.
			if ( self::$settings->get( 'first_setup', 'flags', true ) ) {
				/**
				 * Action hook to execute on first plugin activation.
				 *
				 * @since 4.11.2
				 */
				do_action( 'wpmudev_dashboard_first_activation' );

				// Not a first time activation anymore.
				self::$settings->set( 'first_setup', false, 'flags' );
			}

			// Make sure all Dashboard settings exist in the DB.
			self::$settings->init();

			// Reset the admin-user when plugin is activated.
			if ( $current_user && $current_user->ID ) {
				self::$site->add_allowed_user( $current_user->ID );
			}

			// On next page load we want to redirect user to login page.
			self::$settings->set( 'redirected_v4', false, 'flags' );

			// Set plugin version on activation.
			self::$settings->set( 'version', self::$version, 'general' );

			// Force refresh of all data when plugin is activated.
			self::$settings->set( 'refresh_profile', true, 'flags' );

			$refresh_projects_data = false;
			if ( self::$api->has_key() ) {
				// if already has api key ( previous install / setup ), allow refresh projects data.
				$refresh_projects_data = true;
			} elseif ( empty( self::$api->get_projects_data()['projects'] ) ) {
				// or if empty projects data ( first time activation ), allow refresh projects data.
				$refresh_projects_data = true;
			}
			if ( $refresh_projects_data ) {
				add_action(
					'shutdown',
					function () {
						self::$api->refresh_projects_data( true );// allow no api key.
					}
				);
			}

			self::$site->schedule_shutdown_refresh();
		}

		/**
		 * Run code on plugin deactivation.
		 *
		 * @since    4.1.1
		 * @internal Action hook
		 */
		public function deactivate_plugin() {
			// On next page load we want to redirect user to login page.
			self::$settings->set( 'redirected_v4', false, 'flags' );
		}

		/**
		 * Run code on plugin uninstall.
		 *
		 * @since    4.5
		 * @internal Action hook
		 */
		public static function uninstall_plugin() {
			$keep_data     = self::$settings->get( 'uninstall_keep_data', 'flags' );
			$keep_settings = self::$settings->get( 'uninstall_preserve_settings', 'flags' );
			// On next page load we want to redirect user to login page.
			if ( ! $keep_data && ! $keep_settings ) {
				self::$site->logout( false );
			}
		}

		/**
		 * Run code on plugin version upgrade.
		 *
		 * @since  4.11
		 *
		 * @param string $version Old version.
		 *
		 * @return void
		 */
		private function upgrade_plugin( $version ) {
			// Set new version.
			self::$settings->set( 'version', self::$version, 'general' );

			/**
			 * Action hook to execute upgrade functions.
			 *
			 * @since  4.11
			 *
			 * @param string $new_version New version.
			 *
			 * @param string $version     Old version.
			 */
			do_action( 'wpmudev_dashboard_version_upgrade', $version, self::$version );
		}
	}

	// Initialize the WPMUDEV Dashboard.
	WPMUDEV_Dashboard::instance();
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
if ( ! class_exists( 'WPMUDEV_Update_Notifications' ) ) {

	/**
	 * Dummy class for backwards compatibility to stone-age.
	 */
	class WPMUDEV_Update_Notifications {
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound