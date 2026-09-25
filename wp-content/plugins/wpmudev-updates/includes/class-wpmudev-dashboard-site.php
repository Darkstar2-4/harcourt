<?php
/**
 * Site module.
 * Manages all access to the local WordPress site;
 * For example: Storing and fetching settings, activating plugins, etc.
 *
 * @since   4.0.0
 * @package WPMUDEV_Dashboard
 */

/**
 * The site-module class.
 */
class WPMUDEV_Dashboard_Site {

	/**
	 * URL to the Dashboard plugin; used to load images, etc.
	 *
	 * @var string (URL)
	 */
	public $plugin_url = '';

	/**
	 * Name of the Dashboard plugin directory (relative to wp-content/plugins)
	 *
	 * @var string (Path)
	 */
	public $plugin_dir = '';

	/**
	 * Full path to the plugin directory
	 *
	 * @var string (Path)
	 */
	public $plugin_path = '';

	/**
	 * The PID of the Upfront root theme.
	 * Upfront is required for our modern themes. This is used to automatically
	 * install Upfront when required.
	 *
	 * @var int (Project ID)
	 */
	public $id_upfront = 938297;

	/**
	 * The PID of the Upfront builder plugin.
	 * Upfront is required for this plugin to function. This is used to automatically
	 * install Upfront when required.
	 *
	 * @var int (Project ID)
	 */
	public $id_upfront_builder = 1107287;

	/**
	 * The PID of our "133 Theme Pack" package.
	 * This package needs some special treatment; since it contains many themes
	 * we need to update the package when only one of those themes changed.
	 *
	 * @var int (Project ID)
	 */
	public $id_farm133_themes = 128;

	/**
	 * This is the highest Project-ID of the legacy themes: If the theme has an
	 * higher ID it means that it requires Upfront.
	 *
	 * @var int (Project ID)
	 */
	public $id_legacy_themes = 237;

	/**
	 * Flag that is tripped to schedule api refresh at the end of the page load (avoid multiple)
	 *
	 * @var bool
	 */
	protected static $refresh_shutdown_flag = false;


	/**
	 * Backtraces of shutdown refresh functions
	 *
	 * @var array
	 */
	public static $refresh_shutdown_backtraces = array();

	/**
	 * Latest plugin updates transient captured during this request.
	 *
	 * @var ?object
	 */
	protected static $plugin_updates_transient_snapshot = null;

	/**
	 * Latest theme updates transient captured during this request.
	 *
	 * @var ?object
	 */
	protected static $theme_updates_transient_snapshot = null;

	/**
	 * Memoize the local projects.
	 *
	 * @var ?array
	 */
	protected static $memoized_local_projects = null;

	/**
	 * Memoize the updates.
	 *
	 * @var ?array
	 */
	protected static $memoized_updates = null;

	/**
	 * Memoize the plugin updates.
	 *
	 * @var ?array
	 */
	protected static $memoized_plugin_updates = null;

	/**
	 * Memoize the translation updates.
	 *
	 * @var ?array
	 */
	protected static $memoized_translation_updates = null;

	/**
	 * Memoize the theme updates.
	 *
	 * @var ?array
	 */
	protected static $memoized_theme_updates = null;

	/**
	 * Stores return values of get_project_info()
	 *
	 * @var array|false
	 */
	protected static $cache_project_info = false;

	/**
	 * The noticeid of the SSO notice
	 *
	 * @var int (Task ID's last 4 digits)
	 */
	protected $sso_notice_id = 2927;

	/**
	 * Set up the Site module. Here we load and initialize the settings.
	 *
	 * @since 4.0.0
	 *
	 * @param string $main_file Path to the plugins main file.
	 *
	 * @internal
	 */
	public function __construct( $main_file ) {
		$this->init_flags();

		// Prepare module settings.
		$this->plugin_url  = trailingslashit( plugins_url( '', $main_file ) );
		$this->plugin_dir  = dirname( plugin_basename( $main_file ) );
		$this->plugin_path = trailingslashit( dirname( $main_file ) );

		// Process any actions triggered by the UI (e.g. save data).
		add_action( 'current_screen', array( $this, 'process_actions' ) );

		// Check for compatibility issues and display a notification if needed.
		add_action(
			'admin_init',
			array( $this, 'compatibility_warnings' )
		);

		add_action( 'update-core.php', array( $this, 'refresh_local_projects_wrapper' ), 99 );
		add_action( 'load-plugins.php', array( $this, 'refresh_local_projects_wrapper' ), 99 );
		add_action( 'load-update.php', array( $this, 'refresh_local_projects_wrapper' ), 99 );
		add_action( 'load-update-core.php', array( $this, 'refresh_local_projects_wrapper' ), 99 );
		add_action( 'load-themes.php', array( $this, 'refresh_local_projects_wrapper' ), 99 );
		// really only used when hacking version num.
		add_action( 'load-plugin-editor.php', array( $this, 'refresh_local_projects_wrapper' ), 99 );
		add_action( 'load-theme-editor.php', array( $this, 'refresh_local_projects_wrapper' ), 99 );

		// Refresh after upgrade/install.
		add_action(
			'upgrader_process_complete',
			array( $this, 'after_local_files_changed' ),
			10,
			2
		);
		add_action(
			'set_site_transient_update_plugins',
			array( $this, 'capture_plugin_updates_transient_snapshot' )
		);
		add_action(
			'set_site_transient_update_themes',
			array( $this, 'capture_theme_updates_transient_snapshot' )
		);
		add_filter(
			'delete_site_transient_update_themes',
			array( $this, 'schedule_shutdown_refresh' )
		); // runs when a theme is deleted.

		// refresh after plugin/theme is activated/deactivated/deleted.
		add_action( 'activated_plugin', array( $this, 'schedule_shutdown_refresh' ) );
		add_action( 'deactivated_plugin', array( $this, 'schedule_shutdown_refresh' ) );
		add_action( 'deleted_plugin', array( $this, 'schedule_shutdown_refresh' ) );
		if ( is_multisite() ) {
			add_action( 'update_site_option_allowedthemes', array( $this, 'schedule_shutdown_refresh' ) ); // network enable/disable.
		}
		if ( is_main_site() ) {
			add_action( 'after_switch_theme', array( $this, 'schedule_shutdown_refresh' ) ); // per site activation.
		}

		add_action( 'shutdown', array( $this, 'shutdown_refresh' ) );

		// Add WPMUDEV projects to the WP updates list.
		add_filter(
			'site_transient_update_plugins',
			array( $this, 'filter_plugin_update_count' )
		);

		add_filter(
			'site_transient_update_themes',
			array( $this, 'filter_theme_update_count' )
		);

		// Override the theme/plugin-installation API of WordPress core.
		add_filter(
			'plugins_api',
			array( $this, 'filter_plugin_update_info' ),
			101,
			3 // Run later to work with bad autoupdate plugins.
		);

		// Whitelabel-ing plugin(s) pages.
		add_action(
			'admin_enqueue_scripts',
			array( $this, 'whitelabel_plugin_admin_pages' )
		);

		// Tracking code for analytics module.
		add_action( 'wp_footer', array( $this, 'analytics_tracking_code' ) );

		// Show friendly error in the login screen, if SSO is disabled or user is not logged in the Dashboard.
		add_filter( 'login_message', array( $this, 'show_sso_friendly_error' ) );

		/**
		 * Run custom initialization code for the Site module.
		 *
		 * @since  4.0.0
		 *
		 * @param WPMUDEV_Dashboard_Site $instance The dashboards Site module.
		 */
		do_action( 'wpmudev_dashboard_site_init', $this );

		add_filter(
			'ajax_query_attachments_args',
			array( $this, 'user_can_edit_branding_image' )
		);

		// Handle plugin update changes.
		add_action( 'wpmudev_dashboard_version_upgrade', array( $this, 'upgrade_plugin' ) );

		// Setup first time actions.
		add_action( 'wpmudev_dashboard_first_activation', array( $this, 'first_time_actions' ) );
	}


	/*
	 * *********************************************************************** *
	 * *     INTERNAL HELPER FUNCTIONS
	 * *********************************************************************** *
	 */


	/**
	 * Defines missing const flags with default values.
	 * This saves us from checking `if ( defined( ... ) )` all the time.
	 *
	 * Complete list of all supported Dashboard constants:
	 *
	 * - WPMUDEV_APIKEY .. Default: (undefined)
	 *     Define a static API key that cannot be changed via Dashboard.
	 *     If this constant is used then login/logout functions are not
	 *     available in the UI.
	 *
	 * - WPMUDEV_LIMIT_TO_USER .. Default: ''
	 *     Additional users that can access the Dashboard (comma separated).
	 *     This constant will override the user-list that can be defined on
	 *     the plugins Settings tab.
	 *
	 * - WPMUDEV_DISABLE_REMOTE_ACCESS .. Default: false
	 *     Set to true to disable the plugins external access functions.
	 *
	 * - WPMUDEV_MENU_LOCATION .. Default: '3.012'
	 *     Position of the WPMUDEV menu-item in the admin menu.
	 *
	 * - WPMUDEV_NO_AUTOACTIVATE .. Default: false
	 *     Default behavior of Install button is install + activate.
	 *     Set to true to only install the plugin, no activation.
	 *     (only for plugins on single-site)
	 *
	 * - WPMUDEV_CUSTOM_API_SERVER .. Default: false
	 *     Custom API Server from which to get membership details, etc.
	 *
	 * - WPMUDEV_API_SSLVERIFY .. Default: true
	 *     Set to false if you are having ssl errors connecting to our API (insecure).
	 *
	 * - WPMUDEV_API_UNCOMPRESSED .. Default: false
	 *     Set to true so API calls request uncompressed response values.
	 *
	 * - WPMUDEV_API_DEBUG .. Default: false
	 *     If set to true then all API calls are logged in the WordPress
	 *     logfile. This will only work if WP_DEBUG is enabled as well.
	 *
	 * - WPMUDEV_API_DEBUG_ALL .. Default: false
	 *     Adds more details to the output of WPMUDEV_API_DEBUG.
	 *
	 * - WPMUDEV_IS_REMOTE .. Default: (undefined)
	 *     This flag is set by the ajax handler after verifying an incoming
	 *     request from the remote dashboard. True means: The request is
	 *     authorized and will be processed.
	 *
	 * - WPMUDEV_DISABLE_SSO .. Default: false
	 *     Set to true to disable SSO from the Hub.
	 *
	 *     To disable all remote calls add this in wp-config (not recommended):
	 *     define( 'WPMUDEV_IS_REMOTE', false );
	 *
	 * @since  4.0.0
	 * @internal
	 */
	protected function init_flags() {
		// Do not initialize: WPMUDEV_APIKEY!
		// Do not initialize: WPMUDEV_IS_REMOTE!
		$flags = array(
			'WPMUDEV_LIMIT_TO_USER'         => false,
			'WPMUDEV_DISABLE_REMOTE_ACCESS' => false,
			'WPMUDEV_MENU_LOCATION'         => '3.012',
			'WPMUDEV_NO_AUTOACTIVATE'       => false,
			'WPMUDEV_CUSTOM_API_SERVER'     => false,
			'WPMUDEV_API_UNCOMPRESSED'      => false,
			'WPMUDEV_API_SSLVERIFY'         => true,
			'WPMUDEV_API_DEBUG'             => false,
			'WPMUDEV_API_DEBUG_ALL'         => false,
			'WPMUDEV_DISABLE_SSO'           => false,
		);

		foreach ( $flags as $flag => $default_val ) {
			if ( ! defined( $flag ) ) {
				// constant name is in variable without reducing readability, constant names already predetermined in $flags above.
				define( $flag, $default_val ); // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound
			}
		}
	}

	/**
	 * Upgrade plugin settings after the update.
	 *
	 * TODO: this is not working very well on beta / rc versioning. as both variants will be always < (less than) released version, the upgrade method will always triggered.
	 * we do still want it to be triggered on beta / rc versioning as well, but not in constant manner
	 *
	 * @since  4.11
	 *
	 * @param string $old_version Old version.
	 */
	public function upgrade_plugin( $old_version ) {
		// If upgrading to 4.11.
		if ( version_compare( $old_version, '4.11', '<' ) ) {
			// If branding enabled, branding type is custom.
			if ( WPMUDEV_Dashboard::$settings->get( 'branding_enabled', 'whitelabel' ) ) {
				WPMUDEV_Dashboard::$settings->set( 'branding_type', 'custom', 'whitelabel' );
			}
		}

		// If upgrading to 4.11.2.
		if ( version_compare( $old_version, '4.11.2', '<' ) ) {
			$enabled = (bool) WPMUDEV_Dashboard::$settings->get( 'autoupdate_dashboard', 'flags' );
			// Sync Dash auto update to WP.
			WPMUDEV_Dashboard::$upgrader->change_wp_auto_update( $enabled );
		}

		// If upgrading to 4.11.3.
		if ( version_compare( $old_version, '4.11.3', '<' ) ) {
			$metrics = (array) WPMUDEV_Dashboard::$settings->get( 'metrics', 'analytics', array() );
			if ( ! empty( $metrics ) ) {
				// Add new visits metrics as checked.
				$metrics[] = 'visits';
				// Update metrics.
				WPMUDEV_Dashboard::$settings->set( 'metrics', $metrics, 'analytics' );
			}
		}

		// If upgrading to 4.11.4.
		if ( version_compare( $old_version, '4.11.4', '<' ) ) {
			// Set data settings.
			WPMUDEV_Dashboard::$settings->set( 'uninstall_keep_data', true, 'flags' );
			WPMUDEV_Dashboard::$settings->set( 'uninstall_preserve_settings', true, 'flags' );
			// Refresh profile data to include new user id.
			WPMUDEV_Dashboard::$api->refresh_profile();
		}

		// If upgrading to 4.11.10.
		if ( version_compare( $old_version, '4.11.10', '<' ) ) {
			// Upgrade settings.
			WPMUDEV_Dashboard::$settings->upgrade_41110();
		}

		// Upgrading to 5.0.0.
		if ( version_compare( $old_version, '5.0.0', '<' ) ) {
			// Upgrade.
			WPMUDEV_Dashboard::$settings->upgrade_500();
		}
	}

	/**
	 * Perform first activation actions.
	 *
	 * On first activation if we are on our hosting,
	 * enable SSO
	 *
	 * @since 4.11.2
	 */
	public function first_time_actions() {
		// On our hosting, if it's first time activation enable few services.
		if ( WPMUDEV_Dashboard::$api->is_wpmu_dev_hosting() ) {
			$user_id = get_current_user_id();
			// If we couldn't find a user.
			if ( empty( $user_id ) ) {
				// Let's get an admin user now.
				$users = WPMUDEV_Dashboard::$site->get_available_users();
				if ( ! empty( $users[0] ) ) {
					$user_id = $users[0]->ID;
				}
			}
			// we just setup the settings internally, it will be synced later after logged in, and / or hub-sync called
			// Set a user for SSO.
			WPMUDEV_Dashboard::$settings->set( 'userid', $user_id, 'sso' );
			// Enable SSO.
			WPMUDEV_Dashboard::$settings->set( 'enabled', true, 'sso' );
		}
	}

	/**
	 * Process actions when page loads.
	 *
	 * @since  4.0.0
	 *
	 * @param WP_Screen $current_screen The current_screen object.
	 *
	 * @internal
	 */
	public function process_actions( $current_screen ) {
		// Remove the "Changes saved" message when user refreshes the browser window.
		if ( empty( $_POST ) ) {
			$err = isset( $_GET['failed'] ) ? intval( $_GET['failed'] ) : false;
			$ok  = isset( $_GET['success'] ) ? intval( $_GET['success'] ) : false;

			if ( ( $ok && $ok < time() ) || ( $err && $err < time() ) ) {
				$url = esc_url_raw(
					remove_query_arg( array( 'success', 'failed', 'wpmudev_msg', 'success-action', 'failed-action' ) )
				);
				header( 'X-Redirect-From: SITE process_actions top' );
				wp_safe_redirect( $url );
				exit;
			}
		}

		// Do nothing when the current page is NOT a WPMU DEV menu item.
		if ( ! strpos( $current_screen->base, 'page_wpmudev' ) ) {
			return;
		}

		// Do nothing if either action or nonce is missing.
		if ( empty( $_REQUEST['action'] ) ) {
			return;
		}

		// dynamic nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );

		if ( empty( $_REQUEST['hash'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_REQUEST['hash'] ) );

		// Do nothing if the nonce is invalid.
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Do nothing if the user is not allowed to use the Dashboard.
		if ( ! $this->allowed_user() ) {
			return;
		}

		$res      = $this->process_action( $action );
		$redirect = remove_query_arg( array( 'action', 'hash', 'success', 'failed', 'success-action', 'failed-action' ) );

		switch ( $res ) {
			case 'OK':
				$redirect = add_query_arg( 'success', 10 + time(), $redirect );
				$redirect = add_query_arg( 'success-action', $action, $redirect );
				break;

			case 'ERR':
				$redirect = add_query_arg( 'failed', 10 + time(), $redirect );
				$redirect = add_query_arg( 'failed-action', $action, $redirect );
				break;

			case 'SILENT':
			default:
				// Nothing.
				break;
		}

		if ( $redirect ) {
			header( 'X-Redirect-From: SITE process_actions bottom' );
			wp_safe_redirect( esc_url_raw( $redirect ) );
			exit;
		}
	}

	/**
	 * Internal processing function to execute specific actions upon page load.
	 * When this function is called we already confirmed that the user is
	 * permitted to use the dashboard and that a correct nonce was supplied.
	 *
	 * @since  4.0.0
	 *
	 * @param string $action The action to execute.
	 *
	 * @return string Either OK|SOK|ERR|SILENT.
	 *         OK     .. Action successful. Display "Saved" message.
	 *         ERR    .. Action failed. Display "Failed" message.
	 *         SILENT .. Do not display any message.
	 * @internal
	 */
	protected function process_action( $action ) {
		do_action( 'wpmudev_dashboard_action_' . $action );

		return 'SILENT';
	}

	/**
	 * Clear all output buffers and send an JSON reponse to an Ajax request.
	 *
	 * @since  4.0.0
	 *
	 * @param mixed $data Optional data to return to the Ajax request.
	 */
	public function send_json_success( $data = null ) {
		while ( ob_get_level() ) {
			ob_get_clean();
		}

		wp_send_json_success( $data );
	}

	/**
	 * Clear all output buffers and send an JSON reponse to an Ajax request.
	 *
	 * @since  4.0.0
	 *
	 * @param mixed $data Optional data to return to the Ajax request.
	 */
	public function send_json_error( $data = null ) {
		while ( ob_get_level() ) {
			ob_get_clean();
		}

		wp_send_json_error( $data );
	}


	/*
	 * *********************************************************************** *
	 * *     PUBLIC INTERFACE FOR OTHER MODULES
	 * *********************************************************************** *
	 */


	/**
	 * Returns the value of a plugin option.
	 * The plugins option-prefix is automatically added to the option name.
	 *
	 * Use this function instead of direct access via get_site_option()
	 *
	 * @since      4.0.0
	 *
	 * @param string $name          The option name.
	 * @param bool   $prefix        Optional. Set to false to not prefix the name.
	 * @param mixed  $default_value Optional. Set value to return if option not found.
	 *
	 * @return mixed The option value.
	 * @deprecated 4.11.10 Use WPMUDEV_Dashboard_Settings:get instead.
	 */
	public function get_option( $name, $prefix = true, $default_value = false ) {
		$mapped = WPMUDEV_Dashboard::$settings->deprecated_get_field_mapping( $name );

		return WPMUDEV_Dashboard::$settings->get( $mapped['name'], $mapped['group'], $default_value );
	}

	/**
	 * Updates the value of a plugin option.
	 * The plugins option-prefix is automatically added to the option name.
	 *
	 * Use this function instead of direct access via update_site_option()
	 *
	 * @since      4.0.0
	 *
	 * @param string $name  The option name.
	 * @param mixed  $value The new option value.
	 *
	 * @return bool
	 * @deprecated 4.11.10 Use WPMUDEV_Dashboard::$settings->set().
	 */
	public function set_option( $name, $value ) {
		$mapped = WPMUDEV_Dashboard::$settings->deprecated_get_field_mapping( $name );

		return WPMUDEV_Dashboard::$settings->set( $mapped['name'], $value, $mapped['group'] );
	}

	/**
	 * Add a new plugin setting to the database.
	 * The plugins option-prefix is automatically added to the option name.
	 *
	 * This function will only save the value if the option does not exist yet!
	 *
	 * Use this function instead of direct access via add_site_option()
	 *
	 * @since      4.0.0
	 *
	 * @param string $name  The option name.
	 * @param mixed  $value The new option value.
	 *
	 * @deprecated 4.11.10 Use WPMUDEV_Dashboard::$settings->add().
	 */
	public function add_option( $name, $value ) {
		$mapped = WPMUDEV_Dashboard::$settings->deprecated_get_field_mapping( $name );

		return WPMUDEV_Dashboard::$settings->add( $mapped['name'], $value, $mapped['group'] );
	}

	/**
	 * Returns the value of a plugin transient.
	 * The plugins option-prefix is automatically added to the transient name.
	 *
	 * Use this function instead of direct access via get_site_transient()
	 *
	 * @since      4.0.0
	 *
	 * @param string $name The transient name.
	 *
	 * @return mixed The transient value.
	 * @deprecated 4.11.10 Use Use WPMUDEV_Dashboard::$settings->get_transient().
	 */
	public function get_transient( $name ) {
		return WPMUDEV_Dashboard::$settings->get_transient( $name );
	}

	/**
	 * Updates the value of a plugin transient.
	 * The plugins option-prefix is automatically added to the transient name.
	 *
	 * Use this function instead of direct access via update_site_option()
	 *
	 * @since      4.0.0
	 *
	 * @param string $name       The transient name.
	 * @param mixed  $value      The new transient value.
	 * @param int    $expiration Time until expiration. Default: No expiration.
	 *
	 * @deprecated 4.11.10 Use Use WPMUDEV_Dashboard::$settings->set_transient().
	 */
	public function set_transient( $name, $value, $expiration = 0 ) {
		WPMUDEV_Dashboard::$settings->set_transient( $name, $value, $expiration );
	}

	/**
	 * Returns a usermeta value of the current user.
	 *
	 * @since  4.0.0
	 *
	 * @param string $name The meta-key.
	 *
	 * @return mixed The meta-value.
	 */
	public function get_usermeta( $name ) {
		$user_id = get_current_user_id();

		$value = get_user_meta( $user_id, $name, true );

		return $value;
	}

	/**
	 * Updates a usermeta value of the current user.
	 *
	 * @since  4.0.0
	 *
	 * @param string $name  The transient name.
	 * @param mixed  $value The new transient value.
	 */
	public function set_usermeta( $name, $value ) {
		$user_id = get_current_user_id();

		update_user_meta( $user_id, $name, $value );
	}

	/**
	 * Log out current WPMUDEV user from the local site and erase all cached
	 * data.
	 * After logout, the user is redirected to the login page.
	 *
	 * @since  4.0.5
	 *
	 * @param bool $redirect  If set to false, the user will not be redirected
	 *                        to the login page. Used for remote logout via ajax handler.
	 */
	public function logout( $redirect = true ) {
		// Prevent infinite loops...
		if ( ! WPMUDEV_Dashboard::$api->has_key() ) {
			return;
		}

		// can't logout if define is being used. https://incsub.atlassian.net/browse/WDD-570.
		if ( defined( 'WPMUDEV_APIKEY' ) && WPMUDEV_APIKEY ) {
			return;
		}

		WPMUDEV_Dashboard::$api->hub_unsync();
		// whatever happen in the API, we won't make it harder for you, your intention is simple: logout
		// so regardless what hub_unsync response was, we will clean our self up
		// caveats: when hub_unsync is actually failed in the Hub, this means your site possible still visible there
		// in that case, feel free to contact our support at https://wpmudev.com/.
		WPMUDEV_Dashboard::$api->set_key( '' ); // this has to be first, so any hook that potentially calls API and requires key won't be triggered.
		WPMUDEV_Dashboard::$settings->reset();
		WPMUDEV_Dashboard::$settings->set( 'membership_data', array() );
		WPMUDEV_Dashboard::$settings->set( 'connected_admin', 0, 'general' );
		if ( $redirect ) {
			// Directly redirect to login page.
			$urls = WPMUDEV_Dashboard::$ui->page_urls;
			WPMUDEV_Dashboard::$ui->redirect_to( $urls->dashboard_url );
		}
	}

	/**
	 * Converts the given date-time string or timestamp from GMT to the local
	 * WordPress timezone.
	 *
	 * @since  4.0.0
	 *
	 * @param string|int $time Either a date-time expression or timestamp.
	 *
	 * @return int The timestamp in local WordPress timezone.
	 */
	public function to_localtime( $time ) {
		if ( is_numeric( $time ) ) {
			$gmt_timestamp = intval( $time );
		} else {
			$gmt_timestamp = strtotime( $time );
		}

		if ( ! $time ) {
			return 0;
		}

		$string = date( 'Y-m-d H:i:s', $gmt_timestamp ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$tz     = get_option( 'timezone_string' ); // In Multisite networks this option is from the main blog.

		if ( $tz ) {
			$datetime = date_create( $string, new DateTimeZone( 'UTC' ) );
			if ( ! $datetime ) {
				return 0;
			}
			try {
				$datetime->setTimezone( new DateTimeZone( $tz ) );
				$localtime = strtotime( $datetime->format( 'Y-m-d H:i:s' ) );
			} catch ( Throwable $ex ) {
				$localtime = $gmt_timestamp; // fallback to gmt timestamp.
			}
		} else {
			if ( ! preg_match( '#([0-9]{1,4})-([0-9]{1,2})-([0-9]{1,2}) ([0-9]{1,2}):([0-9]{1,2}):([0-9]{1,2})#', $string, $matches ) ) {
				return 0;
			}

			$gmt_offset = get_option( 'gmt_offset' ); // In Multisite networks this option is from the main blog.

			$string_time = gmmktime( $matches[4], $matches[5], $matches[6], $matches[2], $matches[3], $matches[1] );
			$localtime   = $string_time + $gmt_offset * HOUR_IN_SECONDS;
		}

		return $localtime;
	}

	/**
	 * The proper way to get the array of locally installed products from cache.
	 *
	 * @since  1.0.0
	 *
	 * @param int $project_id  Optional. If set then a single project array
	 *                         will be returned. Default: Return full project list.
	 *
	 * @return array
	 */
	public function get_cached_projects( $project_id = null ) {
		$projects = WPMUDEV_Dashboard::$settings->get_transient( 'local_projects' );

		if ( ! $projects || ! is_array( $projects ) ) {
			// Set param to true to avoid infinite loop.
			$projects = $this->scan_fs_local_projects();
			if ( is_array( $projects ) ) {
				// Save to be able to check for changes later.
				WPMUDEV_Dashboard::$settings->set_transient(
					'local_projects',
					$projects,
					5 * MINUTE_IN_SECONDS
				);
			}
		}

		/**
		 * Override installed WPMU DEV projects.
		 *
		 * @since 5.0.0
		 *
		 * @param array|false $res
		 * @param ?int        $project_id
		 */
		$projects = apply_filters( 'wpmudev_dashboard_get_cached_projects', $projects, $project_id );

		if ( $project_id ) {
			$res = $projects[ $project_id ] ?? false;
		} else {
			$res = $projects;
		}

		return $res;
	}

	/**
	 * Returns lots of details about the specified project.
	 *
	 * @since  4.0.0
	 *
	 * @param int  $pid         The Project ID.
	 * @param bool $fetch_full  Optional. If true, then even potentially
	 *                          time-consuming preparation is done.
	 *                          e.g. load changelog via API.
	 *
	 * @return object Details about the project.
	 */
	public function get_project_info( $pid, $fetch_full = false ) {
		$pid              = intval( $pid );
		$is_network_admin = is_multisite(); // If multisite we only ever do things in network admin.
		$urls             = WPMUDEV_Dashboard::$ui->page_urls;

		/**
		 * Short-circuit project info.
		 *
		 * @since 5.0.0
		 *
		 * @param ?object $pre        The project info.
		 * @param int     $pid        The project ID.
		 * @param bool    $fetch_full Whether to fetch full project info.
		 */
		$pre = apply_filters( 'wpmudev_dashboard_pre_get_project_info', null, $pid, $fetch_full );
		if ( is_object( $pre ) ) {
			return clone $pre;
		}

		if ( ! is_array( self::$cache_project_info ) ) {
			self::$cache_project_info = array();
		}

		// build data if it's not cached or we need changelog and the changelog is missing from cache.
		if ( ! isset( self::$cache_project_info[ $pid ] ) || ( $fetch_full && ! count( self::$cache_project_info[ $pid ]->changelog ) ) ) {
			$res = (object) array(
				'pid'                 => $pid,
				'type'                => '', // Possible: 'plugin' or 'theme'.
				'special'             => false, // Possible  values: false, 'dropin' or 'muplugin'.
				'name'                => '', // Project name.
				'path'                => '', // Full path to main project file.
				'filename'            => '', // Filename, relative to plugins/themes dir.
				'slug'                => '', // Slug used for updates.
				'version_latest'      => '0.0',
				'version_installed'   => '0.0',
				'has_update'          => false, // Is new version available?
				'can_update'          => false, // User has permission to update?
				'can_activate'        => false, // User has permission to activate/deactivate?
				'can_autoupdate'      => false, // If plugin should auto-update?
				'is_compatible'       => true, // Site has all requirements to install project?
				'incompatible_reason' => '', // If is_compatible is false.
				'requires_min_php'    => WPMUDEV_Dashboard::$upgrader->min_php, // Minimum PHP version required.
				'need_upfront'        => false, // Only used by themes.
				'is_installed'        => false, // Installed on current site?
				'is_active'           => false, // WordPress state, i.e. plugin activated?
				'is_hidden'           => false, // Projects can be hidden via API.
				'is_licensed'         => false, // User has license to use this project?
				'is_addon'            => false,
				'default_order'       => 0,
				'downloads'           => 0,
				'popularity'          => 0,
				'release_stamp'       => 0,
				'update_stamp'        => 0,
				'info'                => '',
				'description'         => '',
				'url'                 => (object) array(
					'instructions'     => '',
					'config'           => '',
					'activate'         => '',
					'deactivate'       => '',
					'install'          => '',
					'update'           => '',
					'download'         => '',
					'website'          => '',
					'thumbnail'        => '',
					'thumbnail_square' => '',
					'icon'             => '',
					'banner_1x'        => '',
					'banner_2x'        => '',
					'video'            => '',
					'infos'            => '',
				),
				'changelog'           => array(),
				'features'            => array(),
				'tags'                => array(),
				'screenshots'         => array(),
				'free_version_slug'   => '',
			);

			$remote = WPMUDEV_Dashboard::$api->get_project_data( $pid );
			if ( empty( $remote ) ) {
				// can't return "false", here as Forminator atm `clone` the return value
				// so if it ended up in this case, it would clone a boolean false, which will fatal error
				// making stdClass will avoid fatal error, BUT it means the further check can't simply use `empty($return)` anymore
				// it has to also check `empty($return->pid)` instead.
				self::$cache_project_info[ $pid ] = new stdClass();

				return clone self::$cache_project_info[ $pid ];
			}
			$local           = WPMUDEV_Dashboard::$site->get_cached_projects( $pid );
			$system_projects = WPMUDEV_Dashboard::$site->get_system_projects();

			// General details.
			$res->type             = ( 'theme' === $remote['type'] ? 'theme' : 'plugin' );
			$res->name             = $remote['name'];
			$res->info             = wp_strip_all_tags( $remote['short_description'] );
			$res->description      = $remote['long_description'] ?? '';
			$res->version_latest   = $remote['version'];
			$res->features         = $remote['features'];
			$res->default_order    = isset( $remote['_order'] ) ? intval( $remote['_order'] ) : 0;
			$res->downloads        = intval( $remote['downloads'] );
			$res->popularity       = intval( $remote['popularity'] );
			$res->release_stamp    = intval( $remote['released'] );
			$res->update_stamp     = intval( $remote['updated'] );
			$res->requires_min_php = empty( $remote['requires_min_php'] ) ? WPMUDEV_Dashboard::$upgrader->min_php : $remote['requires_min_php'];
			$res->is_addon         = $remote['is_plugin_addon'] ?? false;

			// Project tags.
			if ( 'plugin' === $res->type ) {
				$tags = WPMUDEV_Dashboard::$ui->tags_data( 'plugin' );
			} else {
				$tags = WPMUDEV_Dashboard::$ui->tags_data( 'theme' );
			}
			foreach ( $tags as $tid => $tag ) {
				$tag['pids'] = array_map( 'intval', $tag['pids'] );
				if ( ! in_array( (int) $pid, $tag['pids'], true ) ) {
					continue;
				}
				$res->tags[ $tid ] = $tag['name'];
			}

			// Status details.
			$res->can_update  = WPMUDEV_Dashboard::$upgrader->user_can_install( $pid );
			$res->is_licensed = WPMUDEV_Dashboard::$upgrader->user_can_install( $pid, true );

			if ( 'theme' === $res->type ) {
				$res->need_upfront = $this->is_upfront_theme( $pid );
			} elseif ( $this->id_upfront_builder === (int) $pid ) { // the upfront builder plugin requires Upfront theme.
				$res->need_upfront = true;
			}

			$res->is_installed = WPMUDEV_Dashboard::$upgrader->is_project_installed( $pid );

			$res->can_autoupdate = ( 1 === (int) $remote['autoupdate'] ); // this has nothing to do with permissions, just project capability.
			$res->is_compatible  = WPMUDEV_Dashboard::$upgrader->is_project_compatible( $pid, $incompatible_reason );

			// Plugin can be active, even if not licensed:
			// E.g. it was installed and then the admin logged out from WPMU DEV
			// Dashboard, or the API-Key was changed from the Hub, ...
			if ( $res->is_installed ) {
				if ( ! empty( $local['name'] ) ) {
					$res->name = $local['name'];
				}
				if ( 'muplugin' === $local['type'] ) {
					$res->special = $local['type'];
				} elseif ( 'dropin' === $local['type'] ) {
					$res->special = $local['type'];
				}
				$res->path              = $local['path'];
				$res->filename          = $local['filename'];
				$res->slug              = $local['slug'];
				$res->version_installed = $local['version'];
				$res->has_update        = WPMUDEV_Dashboard::$upgrader->is_update_available( $pid );

				if ( 'plugin' === $res->type ) {
					if ( ! function_exists( 'is_plugin_active' ) ) {
						include_once ABSPATH . 'wp-admin/includes/plugin.php';
					}

					if ( $is_network_admin ) {
						$res->is_active = is_plugin_active_for_network( $res->filename );
					} else {
						$res->is_active = is_plugin_active( $res->filename );
					}
				} elseif ( 'theme' === $res->type ) {
					if ( $is_network_admin ) {
						$allowed_themes = get_site_option( 'allowedthemes' );
						$res->is_active = ! empty( $allowed_themes[ $res->slug ] );
					} else {
						$res->is_active = get_option( 'stylesheet' ) === $res->slug;
					}
				}
			}
			if ( in_array( (int) $pid, $system_projects, true ) ) {
				// Hardcoded by plugin, those are always hidden!
				$res->is_hidden = true;
			} elseif ( $res->is_installed ) {
				// Installed projects are always visible.
				$res->is_hidden = false;
			} elseif ( 'theme' === $res->type && $this->is_legacy_theme( $pid ) ) {
				// Hide all non-installed legacy themes.
				$res->is_hidden = true;
			} else {
				// Project is not installed, then use flag from API.
				$res->is_hidden = ! $remote['active'];
			}
			if ( 'plugin' === $res->type ) {
				if ( $res->special ) {
					// MU-Plugins and Dropins cannot be (de)activated.
					$res->can_activate = false;
				} elseif ( $is_network_admin ) {
					$res->can_activate = current_user_can( 'manage_network_plugins' );
				} else {
					$res->can_activate = current_user_can( 'activate_plugins' );
				}
			} elseif ( 'theme' === $res->type ) {
				if ( $is_network_admin ) {
					$res->can_activate = current_user_can( 'manage_network_themes' );
				} else {
					$res->can_activate = current_user_can( 'switch_themes' );
				}
			}

			// URLs.
			$res->url->website = esc_url( $remote['url'] );
			if ( ! empty( $remote['thumbnail_large'] ) ) {
				$res->url->thumbnail = esc_url( $remote['thumbnail_large'] );
			} else {
				$res->url->thumbnail = esc_url( $remote['thumbnail'] );
			}
			if ( ! empty( $remote['thumbnail_square'] ) ) {
				$res->url->thumbnail_square = esc_url( $remote['thumbnail_square'] );
			} else {
				$res->url->thumbnail_square = esc_url( $remote['thumbnail'] );
			}
			// Project icon.
			if ( ! empty( $remote['icon'] ) ) {
				$res->url->icon = esc_url( $remote['icon'] );
			} else {
				$res->url->icon = $res->url->thumbnail_square;
			}
			// Project banner.
			$res->url->banner_1x = $res->url->thumbnail;
			$res->url->banner_2x = $res->url->thumbnail;
			if ( ! empty( $remote['banner_1x'] ) ) {
				$res->url->banner_1x = esc_url( $remote['banner_1x'] );
			}
			if ( ! empty( $remote['banner_2x'] ) ) {
				$res->url->banner_2x = esc_url( $remote['banner_2x'] );
			}
			$res->url->video        = esc_url( $remote['video'] );
			$res->url->instructions = WPMUDEV_Dashboard::$api->rest_url( 'usage/' . $pid );

			if ( 'plugin' === $res->type ) {
				if ( $is_network_admin ) {
					if ( empty( $remote['ms_config_url'] ) ) {
						// In case if the plugin doesn't have network settings but have blog settings.
						if ( ! empty( $remote['wp_config_url'] ) ) {
							$res->url->config = esc_url( admin_url( $remote['wp_config_url'] ) );
						}
					} else {
						$res->url->config = esc_url( network_admin_url( $remote['ms_config_url'] ) );
					}
				} elseif ( ! empty( $remote['wp_config_url'] ) ) {
					$res->url->config = esc_url( admin_url( $remote['wp_config_url'] ) );
				}
			}

			$res->url->install  = WPMUDEV_Dashboard::$upgrader->auto_install_url( $pid );
			$res->url->download = esc_url( $remote['url'] );
			if ( ! $res->special ) {
				// I.e. only if plugin is no dropin/muplugin.
				$res->url->update = WPMUDEV_Dashboard::$upgrader->auto_update_url( $pid );
			}
			if ( ! $res->is_compatible ) {
				switch ( $incompatible_reason ) {
					case 'multisite':
						$res->incompatible_reason = __( 'Requires Multisite', 'wpmudev' );
						break;

					case 'buddypress':
						$res->incompatible_reason = __( 'Requires BuddyPress', 'wpmudev' );
						break;

					case 'php':
						/* translators: %s: Required minimum PHP version. */
						$res->incompatible_reason = sprintf( __( 'Requires PHP %s or above', 'wpmudev' ), $res->requires_min_php );
						break;

					default:
						$res->incompatible_reason = __( 'Incompatible', 'wpmudev' );
						break;
				}
			}

			// When not logged in, the project-ID is passed as URL param!
			$pid_sep = WPMUDEV_Dashboard::$api->has_key() ? '#' : '&';

			if ( 'plugin' === $res->type ) {
				$res->url->infos = $urls->plugins_url . $pid_sep . 'pid=' . $pid;

				$res->url->deactivate = 'plugins.php?action=deactivate&plugin=' . rawurlencode( $res->filename );
				$res->url->activate   = 'plugins.php?action=activate&plugin=' . rawurlencode( $res->filename );

				if ( $is_network_admin ) {
					$res->url->deactivate = network_admin_url( $res->url->deactivate );
					$res->url->activate   = network_admin_url( $res->url->activate );
				} else {
					$res->url->deactivate = admin_url( $res->url->deactivate );
					$res->url->activate   = admin_url( $res->url->activate );
				}
				$res->url->deactivate = wp_nonce_url( $res->url->deactivate, 'deactivate-plugin_' . $res->filename );
				$res->url->activate   = wp_nonce_url( $res->url->activate, 'activate-plugin_' . $res->filename );
			} elseif ( 'theme' === $res->type ) {
				$res->url->infos = $urls->themes_url . $pid_sep . 'pid=' . $pid;

				if ( $is_network_admin ) {
					/*
					 * In Network-Admin following theme-actions are disabled:
					 * - Activate
					 * - Configure
					 */
					$res->url->activate = false;
					$res->url->config   = false;
				} else {
					$res->url->activate = wp_nonce_url(
						'themes.php?action=activate&template=' . rawurlencode( $res->filename ) . '&stylesheet=' . rawurlencode( $res->filename ),
						'switch-theme_' . $res->filename
					);
					if ( $res->need_upfront ) {
						$res->url->config = home_url( '/?editmode=true' );
					} else {
						$return_url       = rawurlencode( WPMUDEV_Dashboard::$ui->page_urls->themes_url );
						$res->url->config = admin_url( 'customize.php?return=' . $return_url );
					}
				}
			}
			$res->screenshots = $remote['screenshots'];

			$res->free_version_slug = $remote['free_version_slug'];
			// Temporary fix for Branda and Beehive missing or having invalid free version slugs.
			if ( 9135 === $pid ) {
				$res->free_version_slug = 'branda-white-labeling/ultimate-branding.php';
			}
			if ( 51 === $pid ) {
				$res->free_version_slug = 'beehive-analytics/beehive-analytics.php';
			}
			if ( 2097296 === $pid ) {
				$res->free_version_slug = 'forminator/forminator.php';
			}
			// Temporary fix end.

			// Performance: Only fetch changelog if needed.
			if ( $fetch_full ) {
				$res->changelog = WPMUDEV_Dashboard::$api->get_changelog(
					$pid,
					$res->version_latest
				);
			}

			self::$cache_project_info[ $pid ] = $res;
		}

		// Following flags are not cached.
		if ( self::$cache_project_info[ $pid ] && is_object( self::$cache_project_info[ $pid ] ) && isset( self::$cache_project_info[ $pid ]->pid ) ) {
			self::$cache_project_info[ $pid ]->is_network_admin = $is_network_admin;
		} else {
			self::$cache_project_info[ $pid ] = new stdClass();
		}

		return clone self::$cache_project_info[ $pid ];
	}

	/**
	 * Check if a given theme project id is an Upfront theme.
	 *
	 * @since  3.0.0
	 *
	 * @param int $project_id The project to check.
	 *
	 * @return bool
	 */
	public function is_upfront_theme( $project_id ) {
		if ( (int) $project_id === $this->id_upfront ) {
			return false;
		}
		if ( $project_id <= $this->id_legacy_themes ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if a given theme project id is a legacy theme.
	 *
	 * @since  3.0.0
	 *
	 * @param int $project_id The project to check.
	 *
	 * @return bool
	 */
	public function is_legacy_theme( $project_id ) {
		if ( $project_id > $this->id_legacy_themes ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if root Upfront project is installed.
	 *
	 * @since  3.0.0
	 * @return bool
	 */
	public function is_upfront_installed() {
		$data = $this->get_cached_projects( $this->id_upfront );

		return ( ! empty( $data ) );
	}

	/**
	 * Check if a child Upfront project is installed.
	 *
	 * @since  3.0.0
	 * @return bool
	 */
	public function is_upfront_theme_installed() {
		$result         = false;
		$local_projects = $this->get_cached_projects();

		foreach ( $local_projects as $project_id => $project ) {
			// Quit on first theme installed greater than legacy threshold.
			if ( 'theme' === $project['type'] && $this->is_upfront_theme( $project_id ) ) {
				$result = true;
				break;
			}
		}

		return $result;
	}

	/**
	 * Checks if the current user is in the list of allowed users of the Dashboard.
	 * Allows for multiple users allowed in define, e.g. in this format:
	 *
	 * <code>
	 *  define("WPMUDEV_LIMIT_TO_USER", "1, 10, 15");
	 * </code>
	 *
	 * @since  1.0.0
	 *
	 * @param int $user_id Optional. If empty then the current user-ID is used.
	 *
	 * @return bool
	 */
	public function allowed_user( $user_id = null ) {
		// If this is a remote call from WPMUDEV remote dashboard then allow.
		if ( ! $user_id && defined( 'WPMUDEV_IS_REMOTE' ) && WPMUDEV_IS_REMOTE ) {
			return true;
		}

		// Balk if this is called too early.
		if ( ! $user_id && ! did_action( 'set_current_user' ) ) {
			return false;
		}

		if ( empty( $user_id ) ) {
			/*
			 * @todo calling this too soon bugs out in some wp installs
			 * http://wpmudev.com/forums/topic/urgenti-lost-permission-after-upgrading#post-227543
			 */
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return false;
		}

		$allowed = $this->get_allowed_users( true );
		$allowed = is_array( $allowed ) ? $allowed : array();
		$allowed = array_map( 'intval', $allowed );

		return in_array( (int) $user_id, $allowed, true );
	}

	/**
	 * Grant access to the WPMU DEV Dashboard to a new admin user.
	 *
	 * @since  4.0.0
	 *
	 * @param int $user_id The user to add.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function add_allowed_user( $user_id ) {
		$user = get_userdata( $user_id );

		if ( ! $user || ! is_a( $user, 'WP_User' ) ) {
			// User not found.
			return false;
		}

		$need_cap = 'manage_options';
		if ( is_multisite() ) {
			$need_cap = 'manage_network_options';
		}

		if ( ! $user->has_cap( $need_cap ) ) {
			// User is no admin.
			return false;
		}

		$allowed = $this->get_allowed_users_from_settings();

		if ( in_array( (int) $user_id, $allowed, true ) ) {
			// User was already added.
			return false;
		}

		$allowed[] = $user_id;
		WPMUDEV_Dashboard::$settings->set( 'limit_to_user', $allowed, 'general' );

		/**
		 * Action hook to trigger when a admin user is added to permissions.
		 *
		 * @since 4.11.18
		 *
		 * @param int   $user_id Added user ID.
		 * @param array $allowed Allowed user IDs.
		 */
		do_action( 'wpmudev_after_add_allowed_user', $user_id, $allowed );

		return true;
	}

	/**
	 * Get allowed users from settings / options.
	 *
	 * @return array
	 */
	public function get_allowed_users_from_settings() {
		$allowed = WPMUDEV_Dashboard::$settings->get( 'limit_to_user', 'general', array() );
		$allowed = is_array( $allowed ) ? $allowed : ( is_numeric( $allowed ) ? array( $allowed ) : array() );
		$allowed = array_map( 'intval', $allowed );
		$allowed = array_filter( $allowed );

		return array_values( $allowed );
	}

	/**
	 * Remove access to the WPMU DEV Dashboard from another admin user.
	 *
	 * @since  4.0.0
	 *
	 * @param int $user_id The user to remove.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function remove_allowed_user( $user_id ) {
		$allowed = $this->get_allowed_users_from_settings();
		if ( empty( $allowed ) ) {
			// The allowed-list is still empty.
			return false;
		}

		$key = array_search( (int) $user_id, $allowed, true );
		if ( false === $key ) {
			// User not found in the allowed-list.
			return false;
		}

		unset( $allowed[ $key ] );
		$allowed = array_values( $allowed );
		WPMUDEV_Dashboard::$settings->set( 'limit_to_user', $allowed, 'general' );

		/**
		 * Action hook to trigger when a admin user is removed from permissions.
		 *
		 * @since 4.11.18
		 *
		 * @param int   $user_id Removed user ID.
		 * @param array $allowed Allowed user IDs.
		 */
		do_action( 'wpmudev_after_remove_allowed_user', $user_id, $allowed );

		return true;
	}

	/**
	 * Get a human readable list of users with allowed permissions for the
	 * Dashboard.
	 *
	 * @since  1.0.0
	 *
	 * @param bool $id_only Return only user-IDs or full usernames.
	 *
	 * @return array|bool
	 */
	public function get_allowed_users( $id_only = false ) {
		$result = false;

		if ( defined( 'WPMUDEV_LIMIT_TO_USER' ) && WPMUDEV_LIMIT_TO_USER ) {
			// Hardcoded list of users.
			if ( is_array( WPMUDEV_LIMIT_TO_USER ) ) {
				$allowed = WPMUDEV_LIMIT_TO_USER;
			} else {
				$allowed = explode( ',', WPMUDEV_LIMIT_TO_USER );
				$allowed = array_map( 'trim', $allowed );
			}
			$allowed = array_map( 'intval', $allowed );
		} else {
			$changed = false;

			// Default: Allow users based on DB settings.
			$allowed = WPMUDEV_Dashboard::$settings->get( 'limit_to_user', 'general' );
			if ( $allowed ) {
				$allowed_from_settings = $this->get_allowed_users_from_settings();
				if ( $allowed !== $allowed_from_settings ) {
					$allowed = $allowed_from_settings;
					$changed = true;
				}
			} else {
				// If not set, then add current user as allowed user.
				$cur_user_id = get_current_user_id();
				if ( $cur_user_id ) {
					$allowed = array( $cur_user_id );
					$changed = true;
				} else {
					$allowed = array();
				}
			}

			// Sanitize allowed users after login to Dashboard, so we can
			// react to changes in the user capabilities.
			if ( ! empty( $allowed ) && WPMUDEV_Dashboard::$api->has_key() ) {
				$need_cap = 'manage_options';
				if ( is_multisite() ) {
					$need_cap = 'manage_network_options';
				}

				// Remove invalid users from the allowed-users-list.
				foreach ( $allowed as $key => $user_id ) {
					$user = get_userdata( $user_id );
					if ( ! $user || ! is_a( $user, 'WP_User' ) ) {
						unset( $allowed[ $key ] );
						$changed = true;
					} elseif ( ! $user->has_cap( $need_cap ) ) {
						unset( $allowed[ $key ] );
						$changed = true;
					}
				}

				if ( $changed ) {
					WPMUDEV_Dashboard::$settings->set( 'limit_to_user', $allowed, 'general' );
				}
			}
		}

		if ( $id_only ) {
			$result = $allowed;
		} else {
			$result = array();
			foreach ( $allowed as $user_id ) {
				$user_info = get_userdata( $user_id );
				if ( $user_info ) {
					$result[] = array(
						'id'           => $user_id,
						'name'         => $user_info->display_name,
						'email'        => $user_info->user_email,
						'first_name'   => $user_info->user_firstname,
						'last_name'    => $user_info->user_lastname,
						'username'     => $user_info->user_login,
						'is_me'        => (int) get_current_user_id() === (int) $user_id,
						'profile_link' => get_edit_user_link( $user_id ),
					);
				}
			}
		}

		return $result;
	}

	/**
	 * Get the list of admin users available to add.
	 *
	 * Get all admin capable users which is not added yet
	 * to list.
	 *
	 * @since 4.11.2
	 * @since 4.11.10 Added $limit argument.
	 *
	 * @param int  $limit            Limit no. of users to retrieve.
	 * @param bool $exclude_existing Exclude existing admins.
	 *
	 * @return array
	 */
	public function get_available_users( $limit = 0, $exclude_existing = true ) {
		// We need only IDs for now.
		$args = array(
			'fields'      => array(
				'ID',
				'display_name',
				'user_login',
				'user_email',
			),
			'count_total' => false,
		);

		// Exclude already allowed users.
		if ( $exclude_existing ) {
			// Get already allowed users.
			$allowed = $this->get_allowed_users( true );
			if ( ! empty( $allowed ) ) {
				$args['exclude'] = $allowed;
			}
		}

		// To get from all blogs on multisite.
		if ( is_multisite() ) {
			$args['blog_id'] = 0;
		}

		// Filter only admins.
		if ( is_multisite() ) {
			$args['login__in'] = get_super_admins();
		} else {
			$args['role'] = 'administrator';
		}

		/**
		 * Filter to adjust users query limit.
		 *
		 * @since 4.11.10
		 *
		 * @param int $limit Limit.
		 */
		$limit = apply_filters( 'wpmudev_dashboard_get_available_users_limit', $limit );

		// Limit no. of users for performance.
		if ( ! empty( $limit ) ) {
			$args['number'] = (int) $limit;
		}

		// Get user IDs.
		return get_users( $args );
	}

	/**
	 * Detect if this is a development site running on a private/loopback IP
	 *
	 * @return bool
	 */
	public function is_localhost() {
		$loopbacks = array( '127.0.0.1', '::1' );
		if ( in_array( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ), $loopbacks, true ) ) {
			return true;
		}

		if ( ! filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check user permissions to see if we can install this project.
	 *
	 * NOTE: Not sure why this duplicate exist. Since it's a public function we can not
	 * remove it for now. So deprecating.
	 *
	 * @since      1.0.0
	 *
	 * @param int  $project_id   The project to check.
	 * @param bool $only_license Skip permission check, only validate license.
	 *
	 * @return bool
	 * @deprecated 4.11.17
	 */
	public function user_can_install( $project_id, $only_license = false ) {
		return WPMUDEV_Dashboard::$upgrader->user_can_install( $project_id, $only_license );
	}

	/**
	 * Returns a list of internal/hidden/deprecated projects.
	 *
	 * @since  4.0.0
	 * @return array
	 */
	public function get_system_projects() {
		$list = array(
			// Upfront parent is hidden.
			WPMUDEV_Dashboard::$site->id_upfront,
		);

		return $list;
	}

	/*
	 * *********************************************************************** *
	 * *     INTERNAL ACTION HANDLERS
	 * *********************************************************************** *
	 */


	/**
	 * Check for any compatibility issues or important updates and display a
	 * notice if found.
	 *
	 * @since  4.0.0
	 */
	public function compatibility_warnings() {
		/**
		 * Whether to check for compatibility.
		 *
		 * @since 5.0.0
		 */
		$check_compatibility = apply_filters( 'wpmudev_dashboard_check_compatibility', true );
		if ( ! $check_compatibility ) {
			return;
		}
		if ( $this->is_upfront_theme_installed() && ! $this->is_upfront_installed() ) {
			// Upfront child theme is installed but not parent theme is missing:
			// Only display this on the WP Dashboard page.
			$upfront = $this->get_project_info( $this->id_upfront );

			if ( is_object( $upfront ) && ! empty( $upfront->pid ) ) {
				do_action(
					'wpmudev_override_notice',
					__( '<b>The Upfront parent theme is missing!</b><br>Please install it to use your Upfront child themes', 'wpmudev' ),
					'<a href="' . $upfront->url->install . '" class="button button-primary">Install Upfront</a>'
				);
			}
		}
	}

	/**
	 * Does a filesystem scan for local plugins/themes and caches it. If any
	 * changes found it will trigger remote api check and calculate upgrades as well.
	 *
	 * @since  1.0.0
	 * @since  5.0.0 Allow full sync to be outside controlled
	 *
	 * @param string $check     Either 'local' or 'remote'. Local will only scan
	 *                          the local FS for changes. Remote will also query the API
	 *                          and schedule updates.
	 * @param bool   $full_sync Whether Force the full sync.
	 *
	 * @return array
	 */
	public function refresh_local_projects( $check = 'remote', $full_sync = false ) {
		// 1. Scan local FS to find projects.
		$local_projects = $this->scan_fs_local_projects();

		// 2. Load the cached project-list from DB.
		$saved_local_projects = $this->get_cached_projects();

		// Check for changes.
		$md5_db = md5( wp_json_encode( $saved_local_projects ) );
		$md5_fs = md5( wp_json_encode( $local_projects ) );

		if ( 'remote' === $check || ! hash_equals( $md5_db, $md5_fs ) ) {
			self::reset_memoizer();

			WPMUDEV_Dashboard::$settings->set_transient(
				'local_projects',
				$local_projects,
				5 * MINUTE_IN_SECONDS
			);
			WPMUDEV_Dashboard::$settings->set( 'updates_available', false );

			WPMUDEV_Dashboard::$api->hub_sync( $local_projects, $full_sync );

			// Recalculate upgrades with current/updated data.
			WPMUDEV_Dashboard::$api->calculate_upgrades( $local_projects );
		}

		return $local_projects;
	}

	/**
	 * Used to call refresh_local_projects from a hook (strip passed arguments)
	 *
	 * @since    1.0.0
	 * @internal Action hook
	 */
	public function refresh_local_projects_wrapper() {
		/**
		 * Whether to do refresh local projects from hooks.
		 *
		 * @since 5.0.0
		 */
		$do_refresh = apply_filters( 'wpmudev_dashboard_refresh_local_projects_wrapper', true );
		if ( ! $do_refresh ) {
			return;
		}

		// force-recheck in admin screens.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( function_exists( 'is_admin' ) && is_admin() && function_exists( 'is_user_logged_in' ) && is_user_logged_in() && isset( $_GET['force-check'] ) ) {
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			self::$refresh_shutdown_flag = false;
			WPMUDEV_Dashboard::$api->refresh_projects_data();
			$this->refresh_local_projects( 'remote' );
		} else {
			$this->refresh_local_projects( 'local' );
		}
	}

	/**
	 * This handler is called right before starting an upgrade/installation
	 * process, it makes sure that the upgrader will get uncached and
	 * up-to-date details.
	 *
	 * @since  4.1.0
	 */
	public function clear_local_file_cache() {
		self::$cache_project_info = false;
		WPMUDEV_Dashboard::$settings->set_transient( 'local_projects', false );
	}

	/**
	 * This handler is called right after a plugin was installed or updated.
	 * It instructs the dashboard to flush all caches (i.e. filesystem is
	 * scanned again, the transient is re-generated, ...)
	 *
	 * @since  4.0.7
	 *
	 * @param object $upgrader Upgrader class.
	 * @param array  $args     Hook arguments.
	 */
	public function after_local_files_changed( $upgrader, $args ) {
		self::reset_memoizer();
		$this->clear_local_file_cache();

		// Recalculate available translation updates.
		if ( isset( $args['type'], $args['bulk'] ) && 'translation' === $args['type'] && $args['bulk'] ) {
			WPMUDEV_Dashboard::$api->calculate_translation_upgrades( true );
		}
	}

	/**
	 * Something happened that we need to send data to DEV, so schedule it to run on shutdown hook
	 *
	 * @since  4.2
	 */
	public function schedule_shutdown_refresh() {
		self::$refresh_shutdown_flag = true;
		if ( defined( 'WPMUDEV_API_DEBUG_CRAZY' ) && WPMUDEV_API_DEBUG_CRAZY ) {
			self::$refresh_shutdown_backtraces[] = wp_debug_backtrace_summary( null, 0, false ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_wp_debug_backtrace_summary
		}
	}

	/**
	 * Capture the latest plugin updates transient for this request and schedule a refresh.
	 *
	 * @since 5.0.1
	 *
	 * @param mixed $value Transient value passed by the current hook.
	 */
	public function capture_plugin_updates_transient_snapshot( $value ) {
		// only when it's an object ( contains actual updates data, even when there are no updates, it would still be object contains last_checked ).
		if ( is_object( $value ) ) {
			self::$plugin_updates_transient_snapshot = $value;
		}

		$this->schedule_shutdown_refresh();
	}

	/**
	 * Capture the latest theme updates transient for this request and schedule a refresh.
	 *
	 * @since 5.0.1
	 *
	 * @param mixed $value Transient value passed by the current hook.
	 */
	public function capture_theme_updates_transient_snapshot( $value ) {
		if ( is_object( $value ) ) { // only when it's an object.
			self::$theme_updates_transient_snapshot = $value;
		}

		$this->schedule_shutdown_refresh();
	}

	/**
	 * Get the plugin updates transient captured during this request.
	 *
	 * @since 5.0.1
	 *
	 * @return object|null
	 */
	public function get_plugin_updates_transient_snapshot() {
		return self::$plugin_updates_transient_snapshot;
	}

	/**
	 * Get the theme update transient captured during this request.
	 *
	 * @since 5.0.1
	 *
	 * @return object|null
	 */
	public function get_theme_updates_transient_snapshot() {
		return self::$theme_updates_transient_snapshot;
	}

	/**
	 * Get the state of refresh shutdown flag.
	 *
	 * @since 5.0.0
	 *
	 * @return bool
	 */
	public function get_refresh_shutdown_flag(): bool {
		return self::$refresh_shutdown_flag;
	}

	/**
	 * Reset the state of refresh shutdown flag.
	 *
	 * @since 5.0.0
	 *
	 * @return void
	 */
	public function reset_refresh_shutdown_flag() {
		self::$refresh_shutdown_flag = false;
	}

	/**
	 * Sends latest data to DEV if schedule at end of page load
	 */
	public function shutdown_refresh() {
		if ( self::$refresh_shutdown_flag && ! defined( 'WPMUDEV_REMOTE_SKIP_SYNC' ) ) {
			WPMUDEV_Dashboard::$site->refresh_local_projects( 'remote' );
		}
	}

	/**
	 * Scans all folder locations and compiles a list of WPMU DEV plugins and
	 * themes and header data.
	 * Also saves 133 theme pack themes into option for later use.
	 *
	 * @since  1.0.0
	 * @return array Local projects
	 * @internal
	 */
	protected function scan_fs_local_projects() {
		$projects = array();

		// ----------------------------------------------------------------------------------
		// Plugins directory.
		// ----------------------------------------------------------------------------------
		$plugins_root = WP_PLUGIN_DIR;
		if ( empty( $plugins_root ) ) {
			$plugins_root = ABSPATH . 'wp-content/plugins';
		}

		$items = $this->find_project_files( $plugins_root, '.php', true );
		foreach ( $items as $item ) {
			if ( isset( $projects[ $item['pid'] ] ) ) {
				continue;
			}

			$item['type']             = 'plugin';
			$projects[ $item['pid'] ] = $item;
		}

		// ----------------------------------------------------------------------------------
		// mu-plugins directory.
		// ----------------------------------------------------------------------------------
		$mu_plugins_root = WPMU_PLUGIN_DIR;
		if ( empty( $mu_plugins_root ) ) {
			$mu_plugins_root = ABSPATH . 'wp-content/mu-plugins';
		}

		$items = $this->find_project_files( $mu_plugins_root, '.php', false );
		foreach ( $items as $item ) {
			if ( isset( $projects[ $item['pid'] ] ) ) {
				continue;
			}

			$item['type']             = 'muplugin';
			$projects[ $item['pid'] ] = $item;
		}

		// ----------------------------------------------------------------------------------
		// wp-content directory.
		// ----------------------------------------------------------------------------------
		$content_plugins_root = WP_CONTENT_DIR;
		if ( empty( $content_plugins_root ) ) {
			$content_plugins_root = ABSPATH . 'wp-content';
		}

		$items = $this->find_project_files( $content_plugins_root, '.php', false );
		foreach ( $items as $item ) {
			if ( isset( $projects[ $item['pid'] ] ) ) {
				continue;
			}

			$item['type']             = 'dropin';
			$projects[ $item['pid'] ] = $item;
		}

		// ----------------------------------------------------------------------------------
		// Themes directory.
		// ----------------------------------------------------------------------------------
		if ( ! empty( WP_CONTENT_DIR ) ) {
			$themes_root = WP_CONTENT_DIR . '/themes';
		} else {
			$themes_root = ABSPATH . 'wp-content/themes';
		}

		$items = $this->find_project_files( $themes_root, '.css', true );

		foreach ( $items as $item ) {
			// Skip 133-Farm-Pack themes.
			if ( (int) $item['pid'] === $this->id_farm133_themes ) {
				continue;
			}

			// Skip child themes.
			if ( false !== strpos( $item['filename'], '-child' ) ) {
				continue;
			}

			$item['type']             = 'theme';
			$item['slug']             = basename( dirname( $item['path'] ) );
			$projects[ $item['pid'] ] = $item;
		}

		return $projects;
	}

	/**
	 * Returns an array of relevant files from the specified folder.
	 *
	 * @since  4.0.0
	 *
	 * @param string $path          The absolute path to the base directory to scan.
	 * @param string $ext           File extension to return (i.e. '.php' or '.css').
	 * @param bool   $check_subdirs False will ignore files in sub-directories.
	 *
	 * @return array Details about all WPMU Projects found in the directory.
	 */
	protected function find_project_files( $path, $ext = '.php', $check_subdirs = true ) {
		$files    = array();
		$items    = array();
		$h_dir    = false;
		$h_subdir = false;
		$ext_len  = strlen( $ext );

		if ( is_dir( $path ) ) {
			$h_dir = opendir( $path );
		}

		// phpcs:disable Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( $h_dir && ( $file = readdir( $h_dir ) ) !== false ) {
			if ( substr( $file, 0, 1 ) === '.' ) {
				continue;
			}

			if ( is_dir( $path . '/' . $file ) ) {
				if ( ! $check_subdirs ) {
					continue;
				}

				$h_subdir = opendir( $path . '/' . $file );
				while ( $h_subdir && ( $subfile = readdir( $h_subdir ) ) !== false ) {
					if ( substr( $subfile, 0, 1 ) === '.' ) {
						continue;
					}
					if ( ! is_readable( "$path/$file/$subfile" ) ) {
						continue;
					}

					if ( substr( $subfile, -$ext_len ) === $ext ) {
						$files[] = "$file/$subfile";
					}
				}
				if ( $h_subdir ) {
					closedir( $h_subdir );
				}
			} else {
				if ( ! is_readable( "$path/$file" ) ) {
					continue;
				}

				if ( substr( $file, -$ext_len ) === $ext ) {
					$files[] = $file;
				}
			}
		}
		if ( $h_dir ) {
			closedir( $h_dir );
		}

		// phpcs:disable Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition

		foreach ( $files as $file ) {
			$data = $this->get_id_plugin( "$path/$file" );
			if ( ! empty( $data['id'] ) ) {
				$items[] = array(
					'pid'      => $data['id'],
					'name'     => $data['name'],
					'filename' => $file,
					'path'     => "$path/$file",
					'version'  => $data['version'],
					'slug'     => 'wpmudev_install-' . $data['id'],
				);
			}
		}

		return $items;
	}

	/**
	 * Get our special WDP ID header line from the file.
	 *
	 * @since  1.0.0
	 *
	 * @param string $plugin_file Main file of the plugin.
	 *
	 * @return array Plugin details: name, id, version.
	 * @uses   get_file_data()
	 * @internal
	 */
	protected function get_id_plugin( $plugin_file ) {
		return get_file_data(
			$plugin_file,
			array(
				'name'    => 'Plugin Name',
				'id'      => 'WDP ID',
				'version' => 'Version',
			)
		);
	}

	/**
	 * Hooks into the plugin update api to add our custom api data.
	 *
	 * @since    1.0.0
	 * @since    4.11.10 Added more data.
	 *
	 * @param object $result Default update-info provided by WordPress.
	 * @param string $action What action was requested (theme or plugin?).
	 * @param object $args   Details used to build default update-info.
	 *
	 * @return object Modified theme/plugin update-info.
	 * @internal Action handler
	 */
	public function filter_plugin_update_info( $result, $action, $args ) {
		global $wp_version, $pagenow;

		// Is WordPress processing a plugin or theme? If not, stop.
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		// Get project ID.
		$pid = str_replace( 'wpmudev_install-', '', $args->slug );

		// Is the theme/plugin by WPMUDEV? If not, stop.
		if ( ! is_numeric( $pid ) ) {
			return $result;
		}

		// Get project data.
		$project = WPMUDEV_Dashboard::$site->get_project_info( $pid, true );

		// No need to continue if empty.
		if ( empty( $project->changelog ?? null ) ) {
			return $result;
		}

		$changelog = '';

		foreach ( $project->changelog as $log ) {
			// Should be a proper item.
			if ( empty( $log ) || ! is_array( $log ) ) {
				continue;
			}

			// Changelog version.
			$changelog .= '<h4>' . $log['version'] . '</h4>';
			// Changelog date.
			$changelog .= '<p>' . __( 'Release Date', 'wpmudev' ) . ' - ' . date_i18n( get_option( 'date_format' ), $log['time'] ) . '</p>';

			// Split by line break.
			$notes = explode( "\n", $log['log'] );
			$notes = array_filter( $notes ); // remove empty items.

			if ( ! empty( $notes ) ) {
				$changelog .= '<ul>';
				foreach ( $notes as $note ) {
					// Remove all unwanted tags.
					$note = stripslashes( $note );
					$note = preg_replace( '/(<br ?\/?>|<p>|<\/p>)/', '', $note );
					$note = trim( preg_replace( '/^\s*(\*|\-)\s*/', '', $note ) );
					$note = str_replace( array( '<', '>' ), array( '&lt;', '&gt;' ), $note );
					$note = preg_replace( '/`(.*?)`/', '<code>\1</code>', $note );
					// If empty, skip the item.
					if ( empty( $note ) ) {
						continue;
					}
					$changelog .= '<li>' . wp_kses_post( $note ) . '</li>';
				}
				$changelog .= '</ul>';
			}
		}

		$result = (object) array(
			'name'         => $project->name,
			'slug'         => sanitize_title( $project->name ),
			'version'      => $project->version_installed,
			'homepage'     => $project->url->website,
			'author'       => '<a href="https://wpmudev.com/" target="_blank">WPMU DEV</a>',
			'contributors' => array(
				'wpmudev' => array(
					'profile'      => 'https://wpmudev.com/',
					'avatar'       => WPMUDEV_Dashboard::$site->plugin_url . 'static/images/wpmudev.png',
					'display_name' => 'WPMU DEV',
				),
			),
			'tested'       => $wp_version,
			'added'        => gmdate( 'Y-m-d', $project->release_stamp ),
			'last_updated' => gmdate( 'Y-m-d', $project->update_stamp ),
			'sections'     => array(
				'description' => empty( $project->description ) ? $project->info : $project->description,
				'changelog'   => $changelog,
			),
			'icons'        => array(
				'default' => $project->url->thumbnail_square,
			),
			'banners'      => array(
				'low'  => empty( $project->url->banner_1x ) ? $project->url->thumbnail : $project->url->banner_1x,
				'high' => empty( $project->url->banner_2x ) ? $project->url->thumbnail : $project->url->banner_2x,
			),
		);

		// Add download link only if not plugins page and updates page.
		if ( ! in_array( $pagenow, array( 'plugins.php', 'update-core.php', 'plugin-install.php' ), true ) && $project->is_compatible ) {
			$result->download_link = WPMUDEV_Dashboard::$api->rest_url_auth( 'install/' . $pid );
		}

		return $result;
	}

	/**
	 * Update the transient value of available plugin updates right before WordPress saves it to
	 * the database.
	 *
	 * @since    1.0.0
	 *
	 * @param object $value The transient value that will be saved.
	 *
	 * @return object Modified transient value.
	 * @internal Action hook
	 */
	public function filter_plugin_update_count( $value ) {
		global $wp_version;

		// if no key provided, update won't be doable anyway.
		if ( ! WPMUDEV_Dashboard::$api->has_key() ) {
			return $value;
		}

		$cur_wp_version = preg_replace( '/-.*$/', '', $wp_version );

		if ( ! is_object( $value ) ) {
			return $value;
		}

		if ( is_null( self::$memoized_local_projects ) ) {
			self::$memoized_local_projects = WPMUDEV_Dashboard::$site->get_cached_projects();
		}
		self::$memoized_local_projects = is_array( self::$memoized_local_projects ) ? self::$memoized_local_projects : array();

		// First remove all installed WPMUDEV plugins from the WP update data.
		if ( isset( $value->response ) && is_array( $value->response ) ) {
			foreach ( self::$memoized_local_projects as $local_project ) {
				if ( 'plugin' !== $local_project['type'] ) {
					continue;
				}
				if ( isset( $value->response[ $local_project['filename'] ] ) ) {
					unset( $value->response[ $local_project['filename'] ] );
				}
				if ( isset( $value->no_update[ $local_project['filename'] ] ) ) {
					unset( $value->no_update[ $local_project['filename'] ] );
				}
			}
		}

		if ( is_null( self::$memoized_plugin_updates ) ) {
			self::$memoized_plugin_updates = array();
			if ( is_null( self::$memoized_updates ) ) {
				// Value of 'updates_available' is set by API `calculate_upgrades()`.
				self::$memoized_updates = WPMUDEV_Dashboard::$settings->get( 'updates_available' );
				if ( false === self::$memoized_updates ) {
					self::$memoized_updates = WPMUDEV_Dashboard::$api->calculate_upgrades( self::$memoized_local_projects );
				}
			}

			if ( is_array( self::$memoized_updates ) && count( self::$memoized_updates ) ) {
				foreach ( self::$memoized_updates as $id => $plugin ) {
					if ( 'theme' === $plugin['type'] ) {
						continue;
					}
					if ( 2 === (int) $plugin['autoupdate'] ) {
						continue;
					}

					$package    = '';
					$autoupdate = false;
					$local      = $this->get_cached_projects( $id );
					// Do not continue if slug not found.
					if ( empty( $local['slug'] ) ) {
						continue;
					}
					$compatible = WPMUDEV_Dashboard::$upgrader->is_project_compatible( $id );

					if ( $compatible && 1 === (int) $plugin['autoupdate'] && WPMUDEV_Dashboard::$api->has_key() ) {
						$package = WPMUDEV_Dashboard::$api->rest_url_auth( 'download/' . $id );
					} elseif ( $compatible && 119 === (int) $id ) {
						// Public download url for Dashboard.
						$package = WPMUDEV_Dashboard::$api->rest_url( 'download-dashboard' );
					}

					$thumb = $plugin['thumbnail'] ?? '';

					// Build plugin class.
					$object = (object) array(
						'id'          => "wpmudev/plugins/$id",
						'slug'        => $local['slug'],
						'plugin'      => $plugin['filename'],
						'new_version' => $plugin['new_version'],
						'url'         => $plugin['url'],
						'package'     => $package,
						'icons'       => array(
							'1x'      => $thumb,
							'default' => $thumb,
						),
						'autoupdate'  => $autoupdate,
						'tested'      => $cur_wp_version,
					);

					self::$memoized_plugin_updates[ $plugin['filename'] ] = $object;
				}
			}
		}

		// Finally merge available WPMUDEV updates into default WP update data.
		if ( ! empty( self::$memoized_plugin_updates ) ) {
			if ( isset( $value->response ) && is_array( $value->response ) ) {
				$value->response = array_merge( $value->response, self::$memoized_plugin_updates );
			} else {
				$value->response = self::$memoized_plugin_updates;
			}
		}

		if ( is_null( self::$memoized_translation_updates ) ) {
			self::$memoized_translation_updates = WPMUDEV_Dashboard::$api->calculate_translation_upgrades();
		}

		if ( ! empty( self::$memoized_translation_updates ) ) {
			if ( isset( $value->translations ) && is_array( $value->translations ) ) {
				// translations[] isn't keyed, so make sure to check duplicates.
				$default_keyed_translations = array();
				foreach ( $value->translations as $translation ) {
					$key = implode(
						'|',
						array(
							$translation['type'] ?? '',
							$translation['slug'] ?? '',
							$translation['language'] ?? '',
							$translation['updated'] ?? '',
						)
					);

					$default_keyed_translations[ $key ] = 1;
				}
				foreach ( self::$memoized_translation_updates as $translation_update ) {
					$key = implode(
						'|',
						array(
							$translation_update['type'] ?? '',
							$translation_update['slug'] ?? '',
							$translation_update['language'] ?? '',
							$translation_update['updated'] ?? '',
						)
					);
					if ( isset( $default_keyed_translations[ $key ] ) ) {
						continue;
					}
					$value->translations[] = $translation_update;
				}
			} else {
				$value->translations = self::$memoized_translation_updates;
			}
		}

		return $value;
	}

	/**
	 * Update the transient value of available theme updates right after
	 * WordPress read it from the database.
	 * We add the WPMUDEV theme-updates to the default list of theme updates.
	 *
	 * @since    1.0.0
	 *
	 * @param object $value The transient value that will be saved.
	 *
	 * @return object Modified transient value.
	 * @internal Action hook
	 */
	public function filter_theme_update_count( $value ) {
		global $wp_version;

		// if no key provided, update won't be doable anyway.
		if ( ! WPMUDEV_Dashboard::$api->has_key() ) {
			return $value;
		}

		$cur_wp_version = preg_replace( '/-.*$/', '', $wp_version );

		if ( ! is_object( $value ) ) {
			return $value;
		}

		if ( is_null( self::$memoized_local_projects ) ) {
			self::$memoized_local_projects = WPMUDEV_Dashboard::$site->get_cached_projects();
		}
		self::$memoized_local_projects = is_array( self::$memoized_local_projects ) ? self::$memoized_local_projects : array();

		// First remove all installed WPMUDEV themes from the WP update data.
		if ( isset( $value->response ) && is_array( $value->response ) ) {
			foreach ( self::$memoized_local_projects as $local_project ) {
				if ( 'theme' !== $local_project['type'] ) {
					continue;
				}
				$theme_slug = dirname( $local_project['filename'] );
				if ( isset( $value->response[ $theme_slug ] ) ) {
					unset( $value->response[ $theme_slug ] );
				}
				if ( isset( $value->no_update[ $theme_slug ] ) ) {
					unset( $value->no_update[ $theme_slug ] );
				}
			}
		}

		if ( is_null( self::$memoized_theme_updates ) ) {
			self::$memoized_theme_updates = array();
			// Value of 'updates_available' is set by API `calculate_upgrades()`.
			if ( is_null( self::$memoized_updates ) ) {
				// Value of 'updates_available' is set by API `calculate_upgrades()`.
				self::$memoized_updates = WPMUDEV_Dashboard::$settings->get( 'updates_available' );
				if ( false === self::$memoized_updates ) {
					self::$memoized_updates = WPMUDEV_Dashboard::$api->calculate_upgrades( self::$memoized_local_projects );
				}
			}

			if ( is_array( self::$memoized_updates ) && count( self::$memoized_updates ) ) {
				// Loop all available WPMUDEV updates and merge them into WP updates.
				foreach ( self::$memoized_updates as $id => $theme ) {
					if ( 'theme' !== $theme['type'] ) {
						continue;
					}
					if ( 1 !== (int) $theme['autoupdate'] ) {
						continue;
					}

					$theme_slug = dirname( $theme['filename'] );

					// Build theme listing.
					$object                = array();
					$object['pid']         = $id; // we add this so we can detect it later when wp core autoupdater triggers.
					$object['theme']       = $theme_slug;
					$object['new_version'] = $theme['new_version'];
					$object['url']         = $theme['url'] ?? '';
					$object['package']     = WPMUDEV_Dashboard::$api->rest_url_auth( 'download/' . $id );
					$object['tested']      = $cur_wp_version;

					self::$memoized_theme_updates[ $theme_slug ] = $object;
				}
			}
		}

		// Finally merge available WPMUDEV updates into default WP update data.
		if ( ! empty( self::$memoized_theme_updates ) ) {
			if ( isset( $value->response ) && is_array( $value->response ) ) {
				$value->response = array_merge( $value->response, self::$memoized_theme_updates );
			} else {
				$value->response = self::$memoized_theme_updates;
			}
		}

		return $value;
	}

	/**
	 * Prints our custom async js tracking code in the site footer.
	 *
	 * @since 4.6
	 */
	public function analytics_tracking_code() {
		/**
		 * Filter to enable/disable analytics tracking.
		 *
		 * @param bool $can_track Can be tracked.
		 */
		$can_track = apply_filters( 'wpmudev_dashboard_analytics_tracking', true );

		// Early checks before continue.
		if ( ! $can_track || ! WPMUDEV_Dashboard::$api->has_key() ) {
			return;
		}

		$analytics_enabled    = WPMUDEV_Dashboard::$settings->get( 'enabled', 'analytics' );
		$analytics_site_id    = WPMUDEV_Dashboard::$settings->get( 'site_id', 'analytics' );
		$analytics_tracker    = WPMUDEV_Dashboard::$settings->get( 'tracker', 'analytics' );
		$analytics_script_url = WPMUDEV_Dashboard::$settings->get( 'script_url', 'analytics' );

		$analytics_allowed = WPMUDEV_Dashboard::$api->is_analytics_allowed();
		// define take priority.
		$analytics_js_url = defined( 'WPMUDEV_ANALYTICS_JS_URL' ) ? WPMUDEV_ANALYTICS_JS_URL : $analytics_script_url;
		if ( empty( $analytics_js_url ) ) {
			// default if all empty.
			$analytics_js_url = 'https://analytics.wpmucdn.com/matomo.js';
		}
		$analytics_js_url = esc_url_raw( $analytics_js_url );

		// Check if analytics can be used.
		if ( $analytics_allowed && $analytics_enabled && $analytics_site_id && $analytics_tracker ) {
			// phpcs:disable Generic.WhiteSpace.DisallowSpaceIndent.SpacesUsed
			// phpcs:disable Universal.WhiteSpace.PrecisionAlignment.Found
			// phpcs:disable Generic.WhiteSpace.ScopeIndent.IncorrectExact
			// phpcs:disable Generic.WhiteSpace.ScopeIndent.Incorrect
			?>

			<script type="text/javascript">
              var _paq = _paq || [];
			  <?php
			  if ( is_multisite() ) {
				  // This lets us use page titles view to filter basic results for a subsite based on domain (works with domain mapping too).
				  echo '_paq.push(["setDocumentTitle", "' . get_current_blog_id() . '/" + document.title]);' . PHP_EOL;
				  if ( is_subdomain_install() ) { // makes sure visitors are tracked across multisite (except domain mapped).
					  echo '_paq.push(["setCookieDomain", "*.' . esc_js( wp_parse_url( network_home_url(), PHP_URL_HOST ) ) . '"]);' . PHP_EOL;
					  echo '_paq.push(["setDomains", "*.' . esc_js( wp_parse_url( network_home_url(), PHP_URL_HOST ) ) . '"]);' . PHP_EOL;
				  }
			  }
			  // collect author stats on single post views, excluding pages.
			  if ( is_single() ) {
				  echo '_paq.push([\'setCustomDimension\', 1, \'{"ID":' . esc_js( get_the_author_meta( 'ID' ) ) . ',"name":"' . esc_js( get_the_author_meta( 'display_name' ) ) . '","avatar":"'
				       . esc_js( md5( get_the_author_meta( 'user_email' ) ) ) . '"}\']);' . PHP_EOL;
			  }
			  ?>
              _paq.push(['trackPageView']);
              (function () {
                var u = "<?php echo esc_js( trailingslashit( $analytics_tracker ) ); ?>";
                _paq.push(['setTrackerUrl', u + 'track/']);
                _paq.push(['setSiteId', '<?php echo intval( $analytics_site_id ); ?>']);
                var d   = document, g = d.createElement('script'), s = d.getElementsByTagName('script')[0];
                g.type  = 'text/javascript';
                g.async = true;
                g.defer = true;
                g.src   = '<?php echo esc_js( $analytics_js_url ); ?>';
                s.parentNode.insertBefore(g, s);
              })();
			</script>
			<?php
			// phpcs:enable Generic.WhiteSpace.DisallowSpaceIndent.SpacesUsed
			// phpcs:enable Universal.WhiteSpace.PrecisionAlignment.Found
			// phpcs:enable Generic.WhiteSpace.ScopeIndent.IncorrectExact
			// phpcs:enable Generic.WhiteSpace.ScopeIndent.Incorrect
		}
	}

	/**
	 * Can current blog user access the analytics widget.
	 *
	 * Translates the minimum role as defined in settings into a level capability, then checks if current user
	 *  has that capability.
	 *
	 * @return bool
	 */
	public function user_can_analytics() {
		$cap  = 'level_10'; // default to administrator.
		$role = get_role( WPMUDEV_Dashboard::$settings->get( 'role', 'analytics' ) );
		if ( is_object( $role ) ) {
			for ( $i = 0; $i <= 12; $i++ ) { // admin is 10, but we'll go a little past it just in case!
				if ( isset( $role->capabilities[ 'level_' . $i ] ) && $role->capabilities[ 'level_' . $i ] ) {
					$cap = 'level_' . $i;
				}
			}
		}

		$user_can = current_user_can( $cap );

		/**
		 * Override whether user can view analytics stats
		 *
		 * @since 5.0.0
		 */
		return apply_filters( 'wpmudev_dashboard_user_can_view_analytics_stats', $user_can );
	}

	/**
	 * Exclude branding logo image from media library.
	 *
	 * If current user do not have access to WPMUDEV Dash settings,
	 * hide branding logo from media library.
	 *
	 * @param array $query WP_Query.
	 *
	 * @return array
	 */
	public function user_can_edit_branding_image( $query ) {
		$user = get_current_user_id();
		// Only if allowed user.
		if ( is_admin() && ! $this->allowed_user( $user ) ) {
			$query['post__not_in'] = array( WPMUDEV_Dashboard::$settings->get( 'branding_image_id', 'whitelabel' ) );
		}

		return $query;
	}

	/**
	 * Get Metrics displayed on analytics widget
	 *
	 * @since 4.7
	 * @return array
	 */
	public function get_metrics_on_analytics() {
		$metrics = WPMUDEV_Dashboard::$settings->get( 'metrics', 'analytics' );

		// default to all displayed.
		if ( false === $metrics ) {
			$metrics = array(
				'pageviews',
				'unique_pageviews',
				'page_time',
				'bounce_rate',
				'exit_rate',
				'visits',
			);
		}

		return $metrics;
	}

	/**
	 * Get whitelabel settings as array assoc
	 *
	 * This function included default structure for whitelabel settings
	 * Static call allowed as long `WPMUDEV_Dashboard::$site` initialized
	 *
	 * @since      4.5.3
	 *
	 * @param array $structure Optional array assoc with expectation use when override needed only.
	 *
	 * @return array
	 * @deprecated 4.11.2 Use WPMUDEV_Dashboard::$whitelabel->get_settings().
	 */
	public function get_whitelabel_settings( $structure = array() ) {
		// Deprecated and moved to new class.
		// Not using _deprecated_function() for now to avoid warnings.
		return WPMUDEV_Dashboard::$whitelabel->get_settings( $structure );
	}

	/**
	 * Get WPMUDEV branding that should be used.
	 *
	 * @since      4.6
	 *
	 * @param mixed  $default_branding Default data.
	 * @param string $type             (`all`, `hide_branding`, `hero_image`, `change_footer`, `footer_text`, `hide_doc_link`).
	 *
	 * @return array
	 * @deprecated 4.11.2 Use WPMUDEV_Dashboard_Whitelabel::get_branding().
	 */
	public function get_wpmudev_branding( $default_branding, $type = 'all' ) {
		// Deprecated and moved to new class.
		// Not using _deprecated_function() for now to avoid warnings.
		if ( ! empty( $type ) ) {
			_deprecated_argument( __METHOD__, '4.11.2' );
		}

		return WPMUDEV_Dashboard::$whitelabel->get_branding( $default_branding );
	}

	/**
	 * Get hide branding flag.
	 *
	 * @since      4.6
	 *
	 * @param bool $hide_branding Should hide branding.
	 *
	 * @return bool
	 * @deprecated 4.11.2 Use WPMUDEV_Dashboard_Whitelabel::get_hide_branding().
	 */
	public function get_wpmudev_branding_hide_branding( $hide_branding ) {
		// Deprecated and moved to new class.
		// Not using _deprecated_function() for now to avoid warnings.
		return WPMUDEV_Dashboard::$whitelabel->get_hide_branding( $hide_branding );
	}

	/**
	 * Get Hero Image for branding
	 *
	 * @since      4.6
	 *
	 * @param string $hero_image Hero image link.
	 *
	 * @return string
	 * @deprecated 4.11.2 Use WPMUDEV_Dashboard_Whitelabel::get_branding_hero_image().
	 */
	public function get_wpmudev_branding_hero_image( $hero_image ) {
		// Deprecated and moved to new class.
		// Not using _deprecated_function() for now to avoid warnings.
		return WPMUDEV_Dashboard::$whitelabel->get_branding_hero_image( $hero_image );
	}

	/**
	 * Get Footer Text for branding
	 *
	 * @since      4.6
	 *
	 * @param bool $change_footer Change footer?.
	 *
	 * @return bool
	 * @deprecated 4.11.2 Use WPMUDEV_Dashboard_Whitelabel::get_branding_change_footer().
	 */
	public function get_wpmudev_branding_change_footer( $change_footer ) {
		// Deprecated and moved to new class.
		// Not using _deprecated_function() for now to avoid warnings.
		return WPMUDEV_Dashboard::$whitelabel->get_branding_change_footer( $change_footer );
	}

	/**
	 * Get Footer Text for branding
	 *
	 * @since      4.6
	 *
	 * @param string $footer_text Footer text.
	 *
	 * @return string
	 * @deprecated 4.11.2 Use WPMUDEV_Dashboard_Whitelabel::get_branding_footer_text().
	 */
	public function get_wpmudev_branding_footer_text( $footer_text ) {
		// Deprecated and moved to new class.
		// Not using _deprecated_function() for now to avoid warnings.
		return WPMUDEV_Dashboard::$whitelabel->get_branding_footer_text( $footer_text );
	}

	/**
	 * Get Footer Text for branding
	 *
	 * @since      4.6
	 *
	 * @param bool $hide_doc_link Hide doc link?.
	 *
	 * @return bool
	 * @deprecated 4.11.2 Use WPMUDEV_Dashboard_Whitelabel::get_branding_hide_doc_link().
	 */
	public function get_wpmudev_branding_hide_doc_link( $hide_doc_link ) {
		// Deprecated and moved to new class.
		// Not using _deprecated_function() for now to avoid warnings.
		return WPMUDEV_Dashboard::$whitelabel->get_branding_hide_doc_link( $hide_doc_link );
	}

	/**
	 * This function is where main logic of whitelabel-ing executed across plugins
	 *
	 * Couple hooks can be utilized by other plugin itself, to ease process of whitelabel-ing
	 *
	 * @since 4.6
	 *
	 * @param string $hook_suffix Hook suffix.
	 *
	 * @return void
	 */
	public function whitelabel_plugin_admin_pages( $hook_suffix ) {
		if ( empty( $hook_suffix ) ) {
			return;
		}

		$whitelabel_settings = WPMUDEV_Dashboard::$whitelabel->get_settings();
		$membership_type     = WPMUDEV_Dashboard::$api->get_membership_status();

		// activated.
		if ( ! $whitelabel_settings['enabled'] || ( 'full' !== $membership_type && 'unit' !== $membership_type ) ) {
			return;
		}

		// base page of current screen map-ed to callable function(s).
		$plugin_pages = array(
			/**
			 * Hummingbird
			 */
			// Hummingbird MultiSite/SingleSite dashboard.
			'toplevel_page_wphb'                     => array(
				'wpmudev_whitelabel_sui_plugins_branding',
				'wpmudev_whitelabel_sui_plugins_footer',
				'wpmudev_whitelabel_sui_plugins_doc_links',
			),
			// Hummingbird MultiSite subsite dashboard.
			'toplevel_page_wphb-performance'         => array(
				'wpmudev_whitelabel_sui_plugins_branding',
				'wpmudev_whitelabel_sui_plugins_footer',
				'wpmudev_whitelabel_sui_plugins_doc_links',
			),
			// performance page.
			'hummingbird-pro_page_wphb-performance'  => array(
				'wpmudev_whitelabel_sui_plugins_footer',
				'wpmudev_whitelabel_sui_plugins_doc_links',
			),
			// caching page.
			'hummingbird-pro_page_wphb-caching'      => array(
				'wpmudev_whitelabel_sui_plugins_footer',
				'wpmudev_whitelabel_sui_plugins_doc_links',
			),
			// gzip page.
			'hummingbird-pro_page_wphb-gzip'         => array(
				'wpmudev_whitelabel_sui_plugins_footer',
				'wpmudev_whitelabel_sui_plugins_doc_links',
			),
			// advanced.
			'hummingbird-pro_page_wphb-advanced'     => array(
				'wpmudev_whitelabel_sui_plugins_footer',
				'wpmudev_whitelabel_sui_plugins_doc_links',
			),
			// uptime.
			'hummingbird-pro_page_wphb-uptime'       => array(
				'wpmudev_whitelabel_sui_plugins_footer',
				'wpmudev_whitelabel_sui_plugins_doc_links',
			),
			// minification / Asset Optimization.
			'hummingbird-pro_page_wphb-minification' => array(
				'wpmudev_whitelabel_sui_plugins_footer',
				'wpmudev_whitelabel_sui_plugins_doc_links',
			),

			/**
			 * Smush
			 */
			// Smush dashboard.
			'toplevel_page_smush'                    => array(
				'wpmudev_whitelabel_sui_plugins_branding',
				'wpmudev_whitelabel_sui_plugins_footer',
				'wpmudev_whitelabel_sui_plugins_doc_links',
			),
		);

		/**
		 * Filter Plugin Pages Map to be whitelabel-ed
		 *
		 * This should return array, with `key` WP_Screen.base,
		 * and `value` is array with `callable`, it's queue, first registered, first executed
		 *
		 * Callable function should accept array as parameter,
		 * Within this array there will be `page_base`, `settings`.
		 *
		 * Callable is executed within `admin_head-$hook_suffix`
		 * with `999` as priority, which expected to be last-executed
		 *
		 * If callable are multiple then it works as queue,
		 * First registered, First executed,
		 * means `$callabels[0]` will called first
		 *
		 * If need to attach into another hook example `admin_print_footer_scripts`
		 * then attach it inside its callable function
		 *
		 * Using this filter encouraged, to avoid race condition,
		 * in case plugin hooks is loaded first before Dash plugin initiated,
		 * which will be needed as `WPMUDEV_Dashboard::$whitelabel->get_settings();` must be initiated
		 *
		 * @since 4.6
		 *
		 * @param array $plugin_pages
		 */
		$plugin_pages                        = apply_filters( 'wpmudev_whitelabel_plugin_pages', $plugin_pages );
		$admin_print_footer_scripts_priority = 999;

		// target configured pages.
		if ( in_array( $hook_suffix, array_keys( $plugin_pages ), true ) ) {
			$callables = $plugin_pages[ $hook_suffix ];
			foreach ( $callables as $callable ) {
				add_action( "admin_head-{$hook_suffix}", $callable, $admin_print_footer_scripts_priority );
			}

			return;
		}
	}

	/**
	 * Replace Free version plugin with Pro version if possible
	 *
	 * Since 4.7
	 *
	 * @since 5.0.0 Pass $err detail by reference
	 *
	 * @param int        $project_id Project ID.
	 * @param null       $doing_ajax Force enable/disable ajax-ify response, if `null` it will check wp_doing_ajax().
	 * @param array|null $err        Error details. Passed by reference.
	 *
	 * @return bool
	 */
	public function maybe_replace_free_with_pro( $project_id, $doing_ajax = null, ?array &$err = null ) {
		$err = null; // reset error.
		if ( null === $doing_ajax ) {
			$doing_ajax = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
		}

		$pid          = (int) $project_id;
		$project_info = $this->get_project_info( $pid );
		if ( ! isset( $project_info->pid ) || $pid !== $project_info->pid ) {
			if ( $doing_ajax ) {
				$this->send_json_error( array( 'message' => __( 'Failed to find plugin id.', 'wpmudev' ) ) );
			}

			return false;
		}

		// Plugins with same folder name.
		$special_plugins = array(
			51      => 'beehive-analytics',
			2097296 => 'forminator',
		);

		$free_filename            = '';
		$is_free_installed        = false;
		$is_pro_success_installed = false;
		if ( ! empty( $project_info->free_version_slug ) ) {
			$free_filename = $project_info->free_version_slug;

			// check if its installed.
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$all_plugins = get_plugins();
			foreach ( $all_plugins as $slug => $all_plugin ) {
				$slug          = plugin_basename( $slug );
				$free_filename = plugin_basename( $free_filename );
				if ( $slug === $free_filename ) {
					$is_free_installed = true;
					break;
				}
			}
		}

		// Handle special plugins.
		if ( isset( $special_plugins[ $pid ] ) && $is_free_installed ) {
			// Move free free to another directory.
			WPMUDEV_Dashboard::$utils->rename_plugin( $special_plugins[ $pid ], $special_plugins[ $pid ] . '-free' );
		}

		$local = WPMUDEV_Dashboard::$site->get_cached_projects( $pid );
		// means its not installed yet.
		if ( empty( $local ) ) {
			// INSTALL PRO Plugin.
			$install_pro = WPMUDEV_Dashboard::$upgrader->install( $pid );
			if ( ! $install_pro ) {
				$get_error = WPMUDEV_Dashboard::$upgrader->get_error();
				$err       = $get_error ? $get_error : null;
				if ( $doing_ajax ) {
					$this->send_json_error( $err );
				}

				// Undo free move.
				if ( isset( $special_plugins[ $pid ] ) && $is_free_installed ) {
					WPMUDEV_Dashboard::$utils->rename_plugin( $special_plugins[ $pid ] . '-free', $special_plugins[ $pid ] );
				}

				return false;
			}
			$is_pro_success_installed = true;
		}

		// first thing first, DEACTIVATE.
		// save current state for next usage.
		$orig_active_blog    = is_plugin_active( $free_filename );
		$orig_active_network = is_multisite() && is_plugin_active_for_network( $free_filename );

		// some plugins has their own method of upgrading itself to PRO version
		// free version can be already deleted/uninstalled
		// but somehow free plugins has active status here, so force deactivate free plugin when needed.
		if ( $orig_active_blog || $orig_active_network ) {
			deactivate_plugins( $free_filename, true, $orig_active_network );
		}

		// clear local cache, because we need if fresh data.
		$this->clear_local_file_cache();
		$local        = WPMUDEV_Dashboard::$site->get_cached_projects( $pid );
		$pro_filename = $local['filename'] ?? false;

		// Set with previous state if is not.
		if ( $orig_active_blog || $orig_active_network ) {
			if ( $pro_filename ) {
				$active_blog    = is_plugin_active( $pro_filename );
				$active_network = is_multisite() && is_plugin_active_for_network( $pro_filename );

				if ( $orig_active_blog && ! $active_blog ) {
					$activated = activate_plugin( $pro_filename, false, false, true );
					if ( is_wp_error( $activated ) ) {
						if ( $is_free_installed ) {
							// attempt restore.
							activate_plugin( $free_filename, false, false, true );
						}

						if ( $doing_ajax ) {
							$this->send_json_error( array( 'message' => $activated->get_error_message() ) );
						}

						return false;
					}
				}
				if ( $orig_active_network && ! $active_network ) {
					$activated = activate_plugin( $pro_filename, false, true, true );
					if ( is_wp_error( $activated ) ) {
						if ( $is_free_installed ) {
							// attempt restore.
							activate_plugin( $free_filename, false, true, true );
						}

						if ( $doing_ajax ) {
							$this->send_json_error( array( 'message' => $activated->get_error_message() ) );
						}

						return false;
					}
				}
			}
		}

		if ( $is_free_installed && $is_pro_success_installed ) {
			// DELETE FREE Plugin.
			$delete_free = true;
			if ( isset( $special_plugins[ $pid ] ) ) {
				$this->delete_plugin_directory( WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $special_plugins[ $pid ] . '-free' );
			} else {
				$delete_free = WPMUDEV_Dashboard::$upgrader->delete_plugin( $free_filename, true );
			}
			if ( ! $delete_free ) {
				$get_error = WPMUDEV_Dashboard::$upgrader->get_error();
				$err       = $get_error ? $get_error : null;
				if ( $doing_ajax ) {
					$this->send_json_error( $err );
				}

				return false;
			}
		}

		$this->clear_local_file_cache();

		return true;
	}

	/**
	 * Replace Pro version plugin with free version if possible.
	 *
	 * Since 4.11.9
	 *
	 * @param int $project_id Project ID.
	 *
	 * @return bool
	 */
	public function maybe_replace_pro_with_free( $project_id ) {
		// Get project id.
		$pid = (int) $project_id;
		// Get project info.
		$project_info = $this->get_project_info( $pid );

		// If not a valid project.
		if ( ! isset( $project_info->pid ) || $pid !== $project_info->pid ) {
			return false;
		}

		// If free slug is not found, do not continue.
		if ( empty( $project_info->free_version_slug ) ) {
			return false;
		}

		// Get local plugin data.
		$local = WPMUDEV_Dashboard::$site->get_cached_projects( $project_id );

		// Check if Pro version is already installed.
		$pro_installed      = ! empty( $local['filename'] );
		$pro_active         = false;
		$pro_active_network = false;
		$forminator_pid     = 2097296;

		// Check if Pro version is active if only it's installed.
		if ( $pro_installed ) {
			$pro_active         = is_plugin_active( $local['filename'] );
			$pro_active_network = is_multisite() && is_plugin_active_for_network( $local['filename'] );
		}

		// Make sure functions are ready.
		if ( ! function_exists( 'plugins_api' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		// Get wp.org slug of the plugin.
		$slug = explode( '/', $project_info->free_version_slug );

		// Build plugin API data.
		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug[0],
				'fields' => array(
					'short_description' => false,
					'sections'          => false,
					'requires'          => false,
					'rating'            => false,
					'ratings'           => false,
					'downloaded'        => false,
					'last_updated'      => false,
					'added'             => false,
					'tags'              => false,
					'compatibility'     => false,
					'homepage'          => false,
					'donate_link'       => false,
				),
			)
		);

		// No download link found.
		if ( empty( $api->download_link ) ) {
			return false;
		}

		// Forminator needs to be handled first.
		if ( $forminator_pid === $pid && $pro_installed ) {
			if ( $pro_active || $pro_active_network ) {
				// Deactivate Forminator Pro version now.
				deactivate_plugins( $local['filename'], true, $pro_active_network );
			}

			// Move free forminator to another directory.
			WPMUDEV_Dashboard::$utils->rename_plugin( 'forminator', 'forminator-pro' );
		}

		// Try installing free version now.
		$upgrader       = new Plugin_Upgrader();
		$free_installed = $upgrader->install( $api->download_link );

		// If free installation was success.
		if ( true === $free_installed ) {
			// Deactivate Pro version now.
			if ( $forminator_pid !== $pid && ( $pro_active || $pro_active_network ) ) {
				deactivate_plugins( $local['filename'], true, $pro_active_network );
			}

			// Activate free version.
			$free_activated = activate_plugin(
				$project_info->free_version_slug,
				false,
				$pro_active_network,
				true
			);

			if ( is_wp_error( $free_activated ) ) {
				// Remove newly installed free version.
				if ( $forminator_pid === $pid ) {
					// Delete Forminator free.
					$this->delete_plugin_directory( WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'forminator' );
					// Restore Forminator Pro.
					WPMUDEV_Dashboard::$utils->rename_plugin( 'forminator-pro', 'forminator' );
				} else {
					WPMUDEV_Dashboard::$upgrader->delete_plugin( $project_info->free_version_slug, true );
				}

				// Reactivate Pro version.
				if ( $pro_active || $pro_active_network ) {
					// attempt restore.
					activate_plugin(
						$local['filename'],
						false,
						$pro_active_network,
						true
					);
				}

				return false;
			} elseif ( $forminator_pid === $pid ) {
				// Remove renamed directory.
				$this->delete_plugin_directory( WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'forminator-pro' );
			} else {
				// Delete Pro version now.
				WPMUDEV_Dashboard::$upgrader->delete_plugin( $pid, true );
			}
		} elseif ( $forminator_pid === $pid && $pro_installed ) {
			// Restore Forminator Pro.
			WPMUDEV_Dashboard::$utils->rename_plugin( 'forminator-pro', 'forminator' );

			if ( $pro_active || $pro_active_network ) {
				// attempt restore.
				activate_plugin(
					$local['filename'],
					false,
					$pro_active_network,
					true
				);
			}
		}

		// Make sure to clear caches.
		$this->clear_local_file_cache();

		return true;
	}

	/**
	 * Get Free versions of DEV projects that installed on this site
	 *
	 * @since 4.7
	 * @since 5.0.0 Remove force fetch / refresh projects data. WDD-571.
	 * @return array
	 */
	public function get_installed_free_projects() {
		$projects = WPMUDEV_Dashboard::$api->get_projects_data();
		$projects = $projects['projects'] ?? array();
		// Temporary fix for Branda and Beehive missing or having invalid free version slugs. todo: this should be set in DEV projects settings.
		if ( isset( $projects[9135] ) && empty( $projects[9135]['free_version_slug'] ?? '' ) ) {
			$projects[9135]['free_version_slug'] = 'branda-white-labeling/ultimate-branding.php';
		}
		if ( isset( $projects[51] ) && empty( $projects[51]['free_version_slug'] ?? '' ) ) {
			$projects[51]['free_version_slug'] = 'beehive-analytics/beehive-analytics.php';
		}
		if ( isset( $projects[2097296] ) && empty( $projects[2097296]['free_version_slug'] ?? '' ) ) {
			$projects[2097296]['free_version_slug'] = 'forminator/forminator.php';
		}
		if ( isset( $projects[1107020] ) && empty( $projects[1107020]['free_version_slug'] ?? '' ) ) {
			$projects[1107020]['free_version_slug'] = 'wordpress-popup/popover.php';
		}
		// Temporary fix end.

		$available_free_projects = array();
		foreach ( $projects as $project_id => $project ) {
			if ( ! empty( $project['free_version_slug'] ) ) {
				$available_free_projects[ $project['free_version_slug'] ] = $project;
			}
		}

		$installed_free_projects = array();
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed_plugins = get_plugins();
		foreach ( $installed_plugins as $slug => $installed_plugin ) {
			if ( in_array( $slug, array_keys( $available_free_projects ), true ) ) {
				$plugins_root = WP_PLUGIN_DIR;
				if ( empty( $plugins_root ) ) {
					$plugins_root = ABSPATH . 'wp-content/plugins';
				}
				$file_data = get_file_data( $plugins_root . DIRECTORY_SEPARATOR . $slug, array( 'WDP_ID' => 'WDP ID' ) );

				// Skip WDP ID. this key existence meeans PRO.
				if ( ! empty( $file_data['WDP_ID'] ) ) {
					continue;
				}

				$installed_free_project                 = $available_free_projects[ $slug ];
				$installed_free_project['is_installed'] = true;
				$installed_free_project['has_update']   = false; // for now, not showing update flags for free plugins
				// use name from FREE version.
				$installed_free_project['name'] = $installed_plugin['Name'];
				// use version from FREE version.
				$installed_free_project['version']           = $installed_plugin['Version'];
				$installed_free_project['version_installed'] = $installed_plugin['Version'];
				$installed_free_project['version_latest']    = $installed_plugin['Version']; // for now, not showing update flags for free plugins.
				$installed_free_project['is_active']         = is_multisite() ? is_plugin_active_for_network( $slug ) : is_plugin_active( $slug );
				$installed_free_project['url']               = array(
					'config' => ( function () use ( $installed_free_project ) {
						if ( is_multisite() ) {
							if ( empty( $installed_free_project['ms_config_url'] ) ) {
								// In case if the plugin doesn't have network settings but have blog settings.
								if ( ! empty( $installed_free_project['wp_config_url'] ) ) {
									return esc_url( admin_url( $installed_free_project['wp_config_url'] ) );
								}
							} else {
								return esc_url( network_admin_url( $installed_free_project['ms_config_url'] ) );
							}
						} elseif ( ! empty( $installed_free_project['wp_config_url'] ) ) {
							return esc_url( admin_url( $installed_free_project['wp_config_url'] ) );
						}

						return esc_url( admin_url( 'plugins.php' ) );
					} )(),
				);
				$installed_free_projects[ $slug ]            = $installed_free_project;
			}
		}

		return $installed_free_projects;
	}

	/**
	 * Hooks into the login_message filter to show friendly error in the login screen, when SSO is disabled or user is logged out of Dashboard.
	 *
	 * @since    4.7.3
	 *
	 * @param string $message Message.
	 *
	 * @return string Modified log in message.
	 */
	public function show_sso_friendly_error( $message ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['wdp_sso_fail'] ) ) {
			if ( 'sso_disabled' === $_GET['wdp_sso_fail'] ) {
				$message = '<div id="login_error">Couldn\'t log in with The Hub SSO because SSO is disabled in the WPMU DEV Dashboard.</div>';
			} elseif ( 'no_logged_in_dashboard_user' === $_GET['wdp_sso_fail'] ) {
				$message = '<div id="login_error">Couldn\'t log in with The Hub SSO because you are not logged into the WPMU DEV Dashboard.</div>';
			}
		}

		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $message;
	}

	/**
	 * Delete plugin directory recursively. Only use for removing free plugin directory if
	 * Pro and Free plugin directories are the same.
	 *
	 * @param string $path - plugin directory absolute path.
	 */
	private function delete_plugin_directory( $path ) {
		$files = glob( $path . '/*' );
		foreach ( $files as $file ) {
			is_dir( $file ) ? $this->delete_plugin_directory( $file ) : wp_delete_file( $file );
		}
		rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Reset internal memoizer
	 *
	 * @since 5.0.0
	 * @return void
	 */
	public static function reset_memoizer() {
		self::$memoized_local_projects      = null;
		self::$memoized_updates             = null;
		self::$memoized_plugin_updates      = null;
		self::$memoized_translation_updates = null;
		self::$memoized_theme_updates       = null;
	}
}

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed
/**
 * Returns true if the current member is on a full membership-level.
 *
 * @since  4.0.0
 * @return bool
 */
function is_wpmudev_member() {
	$type = WPMUDEV_Dashboard::$api->get_membership_status();

	return 'full' === $type;
}

/**
 * Returns true if the current member is on a single membership-level.
 * If project ID is specified then validation is: Single membership for that
 * specific project? Otherwise the licensed project ID is returned (or false
 * if the member is not on a single license)
 *
 * @since  4.0.0
 *
 * @param int|false $pid Optional. The project ID to validate.
 *
 * @return bool|int
 */
function is_wpmudev_single_member( $pid = false ) {
	$type                = WPMUDEV_Dashboard::$api->get_membership_status();
	$licensed_project_id = WPMUDEV_Dashboard::$api->get_membership_projects();

	if ( 'single' === $type ) {
		if ( $pid ) {
			return intval( $pid ) === (int) $licensed_project_id;
		} else {
			return $licensed_project_id;
		}
	}

	return false;
}

/**
 * Returns true if the current member has an active membership.
 * Membership can be anything. We just check if it's active,
 *
 * @since  4.11
 *
 * @return boolean
 */
function is_wpmudev_active_member() {
	// Get membership type.
	$type = WPMUDEV_Dashboard::$api->get_membership_status();

	return ! in_array( $type, array( 'free', 'expired', 'paused' ), true );
}

// this function(s) placed here as its not directly related with WPMUDev Site module.
if ( ! function_exists( 'wpmudev_whitelabel_sui_plugins_branding' ) ) {
	/**
	 * Whitelabel-ing Dashboard Hero image WPMUDev plugins that using SharedUI (sui) as base
	 *
	 * Its ONLY for SharedUI that have similar markup
	 * This function will accept no args, since it should called on `admin_head-$hook_suffix`
	 * Try to utilize `WPMUDEV_Dashboard::` if its needed
	 *
	 * @since 4.6
	 */
	function wpmudev_whitelabel_sui_plugins_branding() {
		$whitelabel_settings = WPMUDEV_Dashboard::$whitelabel->get_settings();

		$output = '';
		if ( $whitelabel_settings['branding_enabled'] ) {
			$branding_type = $whitelabel_settings['branding_type'];

			if ( is_multisite() && ! is_network_admin() && $whitelabel_settings['branding_enabled_subsite'] && 'custom' === $branding_type ) {
				if ( has_custom_logo() ) {
					$custom_logo_id = get_theme_mod( 'custom_logo' );
					$image          = wp_get_attachment_image_src( $custom_logo_id, 'full' );
					$image          = $image[0];
				}
			} else {
				$image = 'link' === $branding_type ? $whitelabel_settings['branding_image_link'] : $whitelabel_settings['branding_image'];
			}

			$additional_summary_class = ! empty( $image ) ? 'sui-rebranded' : 'sui-unbranded';
			ob_start();
			// phpcs:disable Generic.WhiteSpace.DisallowSpaceIndent.SpacesUsed
			// phpcs:disable Universal.WhiteSpace.PrecisionAlignment.Found
			?>
			<style>
                #wpbody-content .sui-wrap div.sui-box.sui-summary {
                    background-image: url(<?php echo esc_url( $image ); ?>);
                }

				<?php if ( empty( $image ) ) : ?>
                #wpbody-content .sui-wrap div.sui-box.sui-summary .sui-summary-image-space {
                    display: none;
                }

                #wpbody-content .sui-wrap div.sui-box.sui-summary .sui-summary-segment {
                    width: calc(100% / 2 - 2px);
                }

                #wpbody-content .sui-wrap div.sui-box.sui-summary > div:nth-child(2).sui-summary-segment {
                    padding-left: 0;
                }

                @media (max-width: 600px) {
                    #wpbody-content .sui-wrap div.sui-box.sui-summary .sui-summary-segment {
                        width: 100%;
                    }
                }

				<?php else : ?>
                #wpbody-content .sui-wrap div.sui-box.sui-summary {
                    background-position: 3% 50%;
                }

                @media (max-width: 782px) {
                    #wpbody-content .sui-wrap div.sui-box.sui-summary {
                        background-image: none;
                    }
                }

				<?php endif; ?>
			</style>
			<script type="text/javascript">
              if (typeof window.jQuery !== 'undefined') {
                jQuery(document).ready(function () {
                  var wpmudev_whitelabel_summary_class = function () {
                    var sui_summary = jQuery('#wpbody-content .sui-wrap div.sui-box.sui-summary');
                    sui_summary.addClass('<?php echo esc_html( $additional_summary_class ); ?>');
                  }();
                });
              }
			</script>
			<?php
			// phpcs:enable Generic.WhiteSpace.DisallowSpaceIndent.SpacesUsed
			// phpcs:enable Universal.WhiteSpace.PrecisionAlignment.Found
			$output = ob_get_clean();
		}

		/**
		 * Filter output SUI whitelabel-ing
		 *
		 * @since 4.6
		 */
		$output = apply_filters( 'wpmudev_whitelabel_sui_plugins_branding', $output );
		// escaped above.
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

if ( ! function_exists( 'wpmudev_whitelabel_sui_plugins_footer' ) ) {
	/**
	 * Whitelabel-ing Footer WPMUDev plugins that using SharedUI (sui) as base
	 *
	 * Its ONLY for SharedUI that have similar markup
	 * This function will accept no args, since it should called on `admin_head-$hook_suffix`
	 * Try to utilize `WPMUDEV_Dashboard::` if its needed
	 *
	 * @since 4.6
	 */
	function wpmudev_whitelabel_sui_plugins_footer() {
		$whitelabel_settings = WPMUDEV_Dashboard::$whitelabel->get_settings();

		$output = '';
		if ( $whitelabel_settings['footer_enabled'] ) {
			$text = $whitelabel_settings['footer_text'];
			$text = wp_kses_post( $text ); // sanitize / escape.
			ob_start();
			// phpcs:disable Generic.WhiteSpace.DisallowSpaceIndent.SpacesUsed
			// phpcs:disable Universal.WhiteSpace.PrecisionAlignment.Found
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			<style>
                #wpbody-content .sui-footer {
                    visibility: hidden;
                }

                #wpbody-content .sui-footer-nav {
                    display: none;
                }

                #wpbody-content .sui-footer-social {
                    display: none;
                }
			</style>
			<script type="text/javascript">
              if (typeof window.jQuery !== 'undefined') {
                jQuery(document).ready(function () {
                  var wpmudev_whitelabel_footer = function () {
                    var sui_footer = jQuery('#wpbody-content .sui-footer');
                    sui_footer.html('<?php echo $text; ?>');
                    sui_footer.css('visibility', 'visible');
                  }();
                });
              }
			</script>
			<?php
			// phpcs:enable Generic.WhiteSpace.DisallowSpaceIndent.SpacesUsed
			// phpcs:enable Generic.WhiteSpace.DisallowSpaceIndent.SpacesUsed
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			$output = ob_get_clean();
		}

		/**
		 * Filter output SUI whitelabel-ing
		 *
		 * @since 4.6
		 */
		$output = apply_filters( 'wpmudev_whitelabel_sui_plugins_footer', $output );
		// escaped above.
		echo $output; // phpcs:ignore
	}
}

if ( ! function_exists( 'wpmudev_whitelabel_sui_plugins_docs' ) ) {
	/**
	 * Whitelabel-ing Docs buttons WPMUDev plugins that using SharedUI (sui) as base
	 *
	 * Its ONLY for SharedUI that have similar markup
	 * This function will accept no args, since it should called on `admin_head-$hook_suffix`
	 * Try to utilize `WPMUDEV_Dashboard::` if its needed
	 *
	 * @since 4.6
	 */
	function wpmudev_whitelabel_sui_plugins_doc_links() {
		$whitelabel_settings = WPMUDEV_Dashboard::$whitelabel->get_settings();

		$output = '';
		if ( $whitelabel_settings['doc_links_enabled'] ) {
			ob_start();
			// this one need to be done via javascript
			// because some page have another extra button like `New Test` \ `Recheck-images`.
			// phpcs:disable Generic.WhiteSpace.DisallowSpaceIndent.SpacesUsed
			?>
			<style>
                #wpbody-content .sui-wrap .sui-header .sui-actions-right {
                    visibility: hidden;;
                }
			</style>
			<script type="text/javascript">
              if (typeof window.jQuery !== 'undefined') {
                jQuery(document).ready(function () {
                  var wpmudev_whitelabel_docs = function () {
                    var sui_action_right          = jQuery('#wpbody-content .sui-wrap .sui-header .sui-actions-right');
                    var header_right_action_links = sui_action_right.find('a');
                    if (header_right_action_links.length) {
                      header_right_action_links.each(function () {
                        var link = jQuery(this),
                            href = link.attr('href');
                        // remove docs.*
                        if (/premium\.wpmudev\.org\/(docs|project)\/.*/i.test(href)) {
                          link.remove();
                        }
                      });
                    }
                    sui_action_right.css('visibility', 'visible');
                  }();
                });
              }
			</script>
			<?php
			// phpcs:enable Generic.WhiteSpace.DisallowSpaceIndent.SpacesUsed
			$output = ob_get_clean();
		}

		/**
		 * Filter output SUI whitelabel-ing
		 *
		 * @since 4.6
		 */
		$output = apply_filters( 'wpmudev_whitelabel_sui_plugins_doc_links', $output );
		// no escape required.
		echo $output; // phpcs:ignore
	}
}
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed