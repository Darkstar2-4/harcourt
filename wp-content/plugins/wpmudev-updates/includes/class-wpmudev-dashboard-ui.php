<?php
/**
 * UI class handling all the output, also modifying all the output to represent
 * WPMU DEV brand such as WP Admin pages, notifications and so on.
 *
 * @package WPMU DEV Dashboard
 */

/**
 * UI class handling all the output, also modifying all the output to represent
 * WPMU DEV brand such as WP Admin pages, notifications and so on.
 */
class WPMUDEV_Dashboard_Ui {

	/**
	 * Class name for the react app container on every page.
	 *
	 * @var string $page_container
	 */
	protected $page_container = 'dashboard-admin';

	/**
	 * SUI classes for the react app container on every page.
	 *
	 * @var string $sui_classes
	 */
	protected $sui_classes = 'sui-wrap sui-theme--light';

	/**
	 * An object that defines all the URLs for the Dashboard menu/submenu items.
	 *
	 * @var WPMUDEV_Dashboard_Sui_Page_Urls
	 */
	public $page_urls = null;

	/**
	 * Set up the UI module. This adds all the initial hooks for the plugin
	 *
	 * @internal
	 */
	public function __construct() {
		// Redirect to login screen on first plugin activation.
		add_action( 'load-plugins.php', array( $this, 'login_redirect' ) );

		// Hook up our WordPress customizations.
		add_action( 'init', array( $this, 'setup_branding' ) );

		$this->page_urls = new WPMUDEV_Dashboard_Sui_Page_Urls();

		add_action( 'load-plugins.php', array( $this, 'brand_updates_table' ), 21 ); // Must be called after WP which is 20.

		add_action( 'load-themes.php', array( $this, 'brand_updates_table' ), 21 ); // Must be called after WP which is 20.

		// Some core updates need to be modified via javascript.
		add_action( 'core_upgrade_preamble', array( $this, 'modify_core_updates_page' ) );

		// Filter plugins page.
		add_filter( 'all_plugins', array( $this, 'maybe_hide_dashboard' ) );

		// Analytics.
		add_action( 'wp_dashboard_setup', array( $this, 'analytics_widget_setup' ) );
		add_action( 'wp_network_dashboard_setup', array( $this, 'analytics_widget_setup' ) );
		// Analytics widget scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'analytics_widget_assets' ), 999 );

		// Hide admin notices on login page.
		add_action( 'in_admin_header', array( $this, 'login_hide_admin_notices' ), 10000 );

		/**
		 * Run custom initialization code for the UI module.
		 *
		 * @since  4.0.0
		 *
		 * @param WPMUDEV_Dashboard_Ui $instance The dashboards UI module.
		 */
		do_action( 'wpmudev_dashboard_ui_init', $this );
	}

	/**
	 * Generate dashboard settings array for JS localization.
	 *
	 * @return array
	 */
	private function get_dashboard_settings() {
		$roles = array();
		foreach ( wp_roles()->roles as $key => $site_role ) {
			$roles[ $key ] = $site_role['name'];
		}
		$member = WPMUDEV_Dashboard::$api->get_profile();
		$user   = wp_get_current_user();

		return array(
			'is_network'                => is_multisite(),
			'site_url'                  => network_site_url(),
			'is_localhost'              => WPMUDEV_Dashboard::$site->is_localhost(),
			'api_nonce'                 => wp_create_nonce( 'wp_rest' ),
			'api_url'                   => rest_url( 'wpmudev-dashboard/v1' ),
			'wp_api_url'                => rest_url(),
			'urls'                      => $this->page_urls,
			'version'                   => WPMUDEV_Dashboard::$version,
			'is_logged_in'              => WPMUDEV_Dashboard::$api->has_key(),
			'membership_status'         => WPMUDEV_Dashboard::$api->get_membership_status(),
			'login'                     => array(
				'hub_auth_url'    => WPMUDEV_Dashboard::$api->rest_url( 'site-authenticate' ),
				'team_auth_url'   => WPMUDEV_Dashboard::$api->rest_url( 'site-authenticate-team' ),
				'google_auth_url' => WPMUDEV_Dashboard::$api->rest_url( 'google-auth' ),
				'auth_nonce'      => wp_create_nonce( 'auth_nonce' ),
			),
			'wp_user'                   => array(
				'id'           => $user->ID,
				'username'     => $user->user_login,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'avatar'       => get_avatar_url( get_current_user_id() ),
			),
			'hub_user'                  => $member['profile'] ?? array(),
			'hub_site_id'               => WPMUDEV_Dashboard::$api->get_site_id(),
			'roles'                     => $roles,
			'analytics_metrics'         => array(
				'pageviews'        => __( 'Page Views', 'wpmudev' ),
				'unique_pageviews' => __( 'Unique Page Views', 'wpmudev' ),
				'page_time'        => __( 'Visit Time', 'wpmudev' ),
				'visits'           => __( 'Entrances', 'wpmudev' ),
				'bounce_rate'      => __( 'Bounce Rate', 'wpmudev' ),
				'exit_rate'        => __( 'Exit Rate', 'wpmudev' ),
			),
			'enabled_analytics_metrics' => WPMUDEV_Dashboard::$site->get_metrics_on_analytics(),
			'wp_lang'                   => get_locale(),
			'custom_api_server'         => defined( 'WPMUDEV_CUSTOM_API_SERVER' ) ? WPMUDEV_CUSTOM_API_SERVER : '',
			'is_remote_access_disabled' => defined( 'WPMUDEV_DISABLE_REMOTE_ACCESS' ) && WPMUDEV_DISABLE_REMOTE_ACCESS,
			'is_api_key_using_define'   => defined( 'WPMUDEV_APIKEY' ) && WPMUDEV_APIKEY,
			'features'                  => $this->get_features(),
			'is_tickets_hidden'         => WPMUDEV_Dashboard::$api->has_key() && WPMUDEV_Dashboard::$api->is_tickets_hidden(),
			'system_info'               => array(
				'php' => $this->get_php_vars( array( 'display_errors' ) ),
			),
		);
	}

	/**
	 * Generate dashboard settings for analytics widget only.
	 *
	 * Keep this payload minimal to avoid exposing unnecessary global vars for
	 * the dashboard widget bundle.
	 *
	 * @return array
	 */
	private function get_analytics_widget_settings(): array {
		return array(
			'is_network'                => is_multisite(),
			'api_nonce'                 => wp_create_nonce( 'wp_rest' ),
			'api_url'                   => rest_url( 'wpmudev-dashboard/v1' ),
			'wp_api_url'                => rest_url(),
			'features'                  => array(
				'analytics' => WPMUDEV_Dashboard::$api->is_analytics_allowed(),
			),
			'enabled_analytics_metrics' => WPMUDEV_Dashboard::$site->get_metrics_on_analytics(),
		);
	}

	/**
	 * Removes WPMU DEV Dashboard from native plugins page.
	 *
	 * When?
	 * - White labeling is enabled
	 * - Current user is not a WPMUDEV admin.
	 *
	 * @param array $all_plugins List of installed plugins.
	 *
	 * @return array List of plugins.
	 */
	public function maybe_hide_dashboard( $all_plugins ) {
		// Only when a real user is logged in and it's wp-admin.
		if ( is_admin() && is_user_logged_in() ) {
			// Is current user a allowed user?.
			$allowed_user = WPMUDEV_Dashboard::$site->allowed_user();
			// Get whitelabel settings.
			$whitelabel_settings = WPMUDEV_Dashboard::$whitelabel->get_settings();

			// Hide if not allowed user or white label is enabled.
			if ( ! $allowed_user || $whitelabel_settings['enabled'] ) {
				unset( $all_plugins[ WPMUDEV_Dashboard::$basename ] );
			}
		}

		return $all_plugins;
	}

	/**
	 * Hide admin notices on login page.
	 *
	 * @since 4.11.13
	 *
	 * @return void
	 */
	public function login_hide_admin_notices() {
		$screen = get_current_screen();

		// Hide only on our login page.
		if (
			isset( $screen->id ) && in_array( $screen->id, array( 'toplevel_page_wpmudev', 'toplevel_page_wpmudev-network' ), true )
			&&
			! WPMUDEV_Dashboard::$api->has_key()
		) {
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
		}
	}

	/**
	 * Checks if plugin was just activated, and redirects to login page.
	 * No redirect if plugin was activated via bulk-update.
	 *
	 * @since    1.0.0
	 * @internal Action hook
	 */
	public function login_redirect() {

		// We only redirect right after plugin activation.
		if ( ( empty( $_GET['activate'] ) || 'true' !== $_GET['activate'] ) || ! empty( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$redirect = false;
		} elseif ( WPMUDEV_Dashboard::$api->has_key() ) {
			$redirect = false;
		} else {
			$redirect = true; // this means we are on the right page and not logged in.
		}

		if ( $redirect ) {
			// This is not a valid request.
			if ( defined( 'DOING_AJAX' ) ) {
				$redirect = false;
			} elseif ( ! current_user_can( 'install_plugins' ) ) {
				// User is not allowed to login to the dashboard.
				$redirect = false;
			} elseif ( WPMUDEV_Dashboard::$settings->get( 'redirected_v4', 'flags' ) ) {
				// We already redirected the user to login page before.
				$redirect = false;
			}
		}

		/* ----- Save the flag and redirect if needed ----- */
		if ( $redirect ) {
			WPMUDEV_Dashboard::$settings->set( 'redirected_v4', true, 'flags' );

			// Force refresh of all data during first redirect.
			WPMUDEV_Dashboard::$settings->set( 'refresh_remote', true, 'flags' );
			WPMUDEV_Dashboard::$settings->set( 'refresh_profile', true, 'flags' );

			header( 'X-Redirect-From: UI first_redirect' );
			wp_safe_redirect( $this->page_urls->dashboard_url );
			exit;
		}
	}

	/**
	 * Register our plugin branding.
	 *
	 * I.e. Setup all the things that are NOT on the dashboard page but modify
	 * the look & feel of WordPress core pages.
	 *
	 * @since    1.0.0
	 * @internal Action hook
	 */
	public function setup_branding() {
		/*
		 * If the current user has access to the WPMUDEV Dashboard then we
		 * always set up our branding hooks.
		 */
		if ( ! WPMUDEV_Dashboard::$site->allowed_user() ) {
			return false;
		}

		// Always add this toolbar item, also on front-end.
		add_action(
			'admin_bar_menu',
			array( $this, 'setup_toolbar' ),
			999
		);

		if ( ! is_admin() ) {
			return false;
		}

		// Add branded links to install/update process.
		add_filter(
			'install_plugin_complete_actions',
			array( $this, 'branding_install_plugin_done' ),
			10,
			2
		);
		add_filter(
			'update_plugin_complete_actions',
			array( $this, 'branding_update_plugin_done' ),
			10,
			2
		);

		// Add the menu icon to the admin menu.
		if ( is_multisite() ) {
			$menu_hook = 'network_admin_menu';
		} else {
			$menu_hook = 'admin_menu';
		}

		add_action(
			$menu_hook,
			array( $this, 'setup_menu' )
		);

		// Always load notification css.
		add_action(
			'admin_print_styles',
			array( $this, 'notification_styles' )
		);
	}

	/**
	 * Here we will set up custom code to display WPMUDEV plugins/themes on the
	 * pages for WP Updates, Themes and Plugins.
	 *
	 * @since  4.0.0
	 */
	public function brand_updates_table() {
		global $pagenow;
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		// don't show on per site plugins list, just like core.
		if ( is_multisite() && ! is_network_admin() ) {
			return;
		}

		if ( WPMUDEV_Dashboard::$api->has_key() ) { // update notices only when it's logged in.
			$updates = WPMUDEV_Dashboard::$settings->get( 'updates_available' );
			if ( is_array( $updates ) && count( $updates ) ) {
				foreach ( $updates as $item ) {
					if ( ! empty( $item['autoupdate'] ) && 2 !== $item['autoupdate'] ) {
						if ( 'theme' === $item['type'] ) {
							$hook = 'after_theme_row_' . dirname( $item['filename'] );
						} else {
							$hook = 'after_plugin_row_' . $item['filename'];
						}
						remove_all_actions( $hook );
						add_action( $hook, array( $this, 'brand_updates_plugin_row' ), 9, 2 );
					}
				}
			}
		} elseif ( 'plugins.php' === $pagenow ) { // only for plugins, nobody cares anymore about themes.
			// generic message for non-logged in users https://incsub.atlassian.net/browse/WDD-573.
			// re-trigger internal WP hook.
			$all_plugins = apply_filters( 'all_plugins', get_plugins() ); // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

			// don't override existing updates.
			// note: the Pro Plugins that has same "name" possibly get updates from wp.org: https://core.trac.wordpress.org/ticket/23318.
			$updates = get_site_transient( 'update_plugins' );
			$updates = is_object( $updates ) && isset( $updates->response ) ? $updates->response : array();
			foreach ( $all_plugins as $key => $plugin ) {
				if ( ! empty( $plugin['WDP ID'] ) && ! isset( $updates[ $key ] ) ) {
					$hook = 'after_plugin_row_' . $key;
					remove_all_actions( $hook );
					add_action( $hook, array( $this, 'brand_generic_plugin_row' ), 9, 2 );
				}
			}
		}
	}

	/**
	 * Output a single plugin-row inside the core WP update-plugins list.
	 *
	 * Though the name says "plugin_row", this function is also used to render
	 * rows inside the themes-update list. Code is identical.
	 *
	 * @since  4.0.5
	 *
	 * @param string $file        The plugin ID (dir- and filename).
	 * @param array  $plugin_data Plugin details.
	 */
	public function brand_updates_plugin_row( $file, $plugin_data ) {
		// Get new version and update URL.
		$updates = WPMUDEV_Dashboard::$settings->get( 'updates_available' );

		if ( ! is_array( $updates ) || ! count( $updates ) ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$project    = false;
		$project_id = null;

		foreach ( $updates as $id => $plugin ) {
			$slug = 'theme' === $plugin['type'] ? dirname( $plugin['filename'] ) : $plugin['filename'];
			if ( $slug === $file ) {
				$project_id = $id;
				$project    = $plugin;
				break;
			}
		}

		if ( $project && $project_id ) {
			$this->brand_updates_row_output( $project_id, $project, $plugin_data['Name'] );
		}
	}

	/**
	 * Shared helper used by brand_updates_* functions above.
	 * This function actually renders the table row with the update text.
	 *
	 * @since  4.0.5
	 *
	 * @param int    $project_id   Our internal project-ID.
	 * @param array  $project      The project details.
	 * @param string $project_name The plugin/theme name.
	 */
	protected function brand_updates_row_output( $project_id, $project, $project_name ) {
		$item = WPMUDEV_Dashboard::$site->get_project_info( $project_id );
		if ( empty( $item->pid ?? null ) ) {
			return;
		}
		$version    = $project['new_version'];
		$plugin_url = $project['url'];
		$autoupdate = $project['autoupdate'];
		$filename   = $project['filename'];

		$plugins_allowedtags = array(
			'a'       => array(
				'href'     => array(),
				'title'    => array(),
				'class'    => array(),
				'target'   => array(),
				'data-pid' => array(),
			),
			'abbr'    => array( 'title' => array() ),
			'acronym' => array( 'title' => array() ),
			'code'    => array(),
			'em'      => array(),
			'strong'  => array(),
		);

		$plugin_name = wp_kses( $project_name, $plugins_allowedtags );

		$url_action    = false;
		$url_changelog = self_admin_url( 'plugin-install.php' );
		$url_changelog = add_query_arg(
			array(
				'tab'       => 'plugin-information',
				'plugin'    => 'wpmudev_install-' . $project_id,
				'section'   => 'changelog',
				'TB_iframe' => 'true',
				'width'     => 600,
				'height'    => 800,
			),
			$url_changelog
		);

		// Is compatible.
		$is_compatible = WPMUDEV_Dashboard::$upgrader->is_project_compatible( $project_id, $reason );

		if ( ! $is_compatible ) {
			if ( 'php' === $reason ) {
				// Incompatible PHP version.
				/* translators: %1$s: Plugin name, %2$s: Plugin details link, %3$s: Plugin details link title, %4$s: Plugin version, %6$s: Required minimum PHP version. */
				$row_text = __(
					'There is a new version of %1$s available, but it is not compatible with your current PHP version. To update to the latest %1$s version, please upgrade your PHP to version %6$s or above. <a href="%2$s" title="%3$s" class="thickbox open-plugin-details-modal">View version %4$s details</a>.',
					'wpmudev'
				);
			} else {
				// Other incompatibilities.
				/* translators: %1$s: Plugin name, %2$s: Plugin details link, %3$s: Plugin details link title, %4$s: Plugin version. */
				$row_text = __(
					'There is a new version of %1$s available, but it is not compatible. <a href="%2$s" title="%3$s" class="thickbox open-plugin-details-modal">View version %4$s details</a>.',
					'wpmudev'
				);
			}
		} elseif ( WPMUDEV_Dashboard::$upgrader->user_can_install( $project_id ) ) {
			// Current user is logged in and has permission for this plugin.
			if ( $autoupdate ) {
				// All clear: One-Click-Update is available for this plugin!
				$url_action = WPMUDEV_Dashboard::$upgrader->auto_update_url( $project_id );
				/* translators: %1$s: Plugin name, %2$s: Plugin details link, %3$s: Plugin details link title, %4$s: Plugin version, %5$s: Update link. */
				$row_text = __(
					'There is a new version of %1$s available on WPMU DEV. <a href="%2$s" title="%3$s" class="thickbox open-plugin-details-modal">View version %4$s details</a> or <a href="%5$s" class="update-link">update now</a>.',
					'wpmudev'
				);
			} else {
				// Can only be manually installed.
				$url_action = $plugin_url;
				/* translators: %1$s: Plugin name, %2$s: Plugin details link, %3$s: Plugin details link title, %4$s: Plugin version, %5$s: Download link. */
				$row_text = __(
					'There is a new version of %1$s available on WPMU DEV. <a href="%2$s" title="%3$s" class="thickbox open-plugin-details-modal">View version %4$s details</a> or <a href="%5$s" target="_blank" title="Download update from WPMU DEV">download update</a>.',
					'wpmudev'
				);
			}
		} elseif ( WPMUDEV_Dashboard::$site->allowed_user() ) {
			// User has no permission for the plugin (anymore) -- due to membership. But "capabilities" wise they can update WPMU DEv Plugins.
			$url_action = apply_filters(
				'wpmudev_project_upgrade_url',
				$this->page_urls->remote_site . 'wp-login.php?redirect_to=' . rawurlencode( $plugin_url ) . '#signup',
				$project_id
			);
			/* translators: %1$s: Plugin name, %2$s: Plugin details link, %3$s: Plugin details link title, %4$s: Plugin version, %5$s: Upgrade link. */
			$row_text = __(
				'There is a new version of %1$s available on WPMU DEV. <a href="%2$s" title="%3$s" class="thickbox open-plugin-details-modal">View version %4$s details</a> or <a href="%5$s" target="_blank" title="Upgrade your WPMU DEV membership">upgrade to update</a>.',
				'wpmudev'
			);
		} else {
			// This user has no permission to use WPMUDEV Dashboard.
			/* translators: %1$s: Plugin name, %2$s: Plugin details link, %3$s: Plugin details link title, %4$s: Plugin version. */
			$row_text = __( 'There is a new version of %1$s available on WPMU DEV. <a href="%2$s" title="%3$s" class="thickbox open-plugin-details-modal">View version %4$s details</a>.', 'wpmudev' );
		}

		if ( is_network_admin() ) {
			$active_class = is_plugin_active_for_network( $filename ) ? ' active' : '';
		} else {
			$active_class = is_plugin_active( $filename ) ? ' active' : '';
		}

		?>
		<tr
				class="plugin-update-tr<?php echo esc_attr( $active_class ); ?>"
				id="<?php echo esc_attr( dirname( $filename ) ); ?>-update"
				data-slug="<?php echo esc_attr( dirname( $filename ) ); ?>"
				data-plugin="<?php echo esc_attr( $filename ); ?>"
		>
			<td colspan="4" class="plugin-update colspanchange">
				<div class="update-message notice inline notice-warning notice-alt">
					<p>
						<?php
						printf(
							wp_kses( $row_text, $plugins_allowedtags ),
							esc_html( $plugin_name ),
							esc_url( $url_changelog ),
							esc_attr( $plugin_name ),
							esc_html( $version ),
							esc_url( $url_action ),
							esc_html( $item->requires_min_php )
						);
						?>
					</p>
					<?php
					/**
					 * Append content to an update notice (Only for Pro plugins).
					 *
					 * @since 4.11.13
					 *
					 * @param int    $project_id Plugin ID.
					 * @param string $version    New version.
					 * @param array  $project    Project data (Will be empty if Dashboard plugin is not active).
					 */
					do_action( 'wpmudev_dashboard_after_update_row_message', $project_id, $version, $project );
					?>
				</div>
				<?php
				/**
				 * Add content after a plugin update notice (Only for Pro plugins).
				 *
				 * @since 4.11.13
				 *
				 * @param int    $project_id Plugin ID.
				 * @param string $version    New version.
				 * @param array  $project    Project data (Will be empty if Dashboard plugin is not active).
				 */
				do_action( 'wpmudev_dashboard_after_update_row_content', $project_id, $version, $project );
				// phpcs:disable Generic.WhiteSpace.DisallowSpaceIndent.SpacesUsed
				// phpcs:disable Universal.WhiteSpace.PrecisionAlignment.Found
				?>
				<?php if ( ! $is_compatible ) : ?>
					<script>
                      let checkbox = jQuery('input:checkbox[value="<?php echo esc_attr( $filename ); ?>"]');
                      checkbox.prop('disabled', true).prop('checked', false).attr('name', '').addClass('disabled');
					</script>
				<?php endif; ?>
				<?php
				// phpcs:enable Generic.WhiteSpace.DisallowSpaceIndent.SpacesUsed
				// phpcs:enable Universal.WhiteSpace.PrecisionAlignment.Found
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Output a single plugin-row inside the core WP update-plugins list.
	 * Generic Variant: https://incsub.atlassian.net/browse/WDD-573. Where Dash is logged out ( no-key ).
	 *
	 * @since  5.0.0
	 *
	 * @param string $filename    The plugin ID (dir- and filename).
	 * @param array  $plugin_data Plugin details.
	 */
	public function brand_generic_plugin_row( $filename, $plugin_data ) {
		if ( is_network_admin() ) {
			$active_class = is_plugin_active_for_network( $filename ) ? ' active' : '';
		} else {
			$active_class = is_plugin_active( $filename ) ? ' active' : '';
		}
		?>
		<tr
				class="plugin-update-tr<?php echo esc_attr( $active_class ); ?>"
				id="<?php echo esc_attr( dirname( $filename ) ); ?>-update"
				data-slug="<?php echo esc_attr( dirname( $filename ) ); ?>"
				data-plugin="<?php echo esc_attr( $filename ); ?>"
		>
			<td colspan="4" class="plugin-update colspanchange">
				<div class="update-message notice inline notice-warning notice-alt">
					<p>
						<?php
						printf(
							wp_kses(
							/* translators: %1$s: Login Link, %2$s: Plugin Name. */
								__(
									'Your site’s currently disconnected from WPMU DEV. <a href="%1$s" target="_blank" title="Login">Login</a> to keep getting %2$s updates and unlock full features.',
									'wpmudev'
								),
								array(
									'a' => array(
										'href'   => array(),
										'target' => array(),
										'title'  => array(),
									),
								)
							),
							esc_url( $this->page_urls->dashboard_url ),
							esc_html( $plugin_data['Name'] ?? $filename )
						);
						?>
					</p>
					<?php
					/**
					 * Append content to an generic notice (Only for WPMU DEV plugins).
					 *
					 * @since 5.0.0
					 *
					 * @param string $filename    Filename.
					 * @param array  $plugin_data Plugin Data.
					 */
					do_action( 'wpmudev_dashboard_after_generic_row_message', $filename, $plugin_data );
					?>
				</div>
				<?php
				/**
				 * Add content after a generic notice (Only for Pro plugins).
				 *
				 * @since 5.0.0
				 *
				 * @param string $filename    Filename.
				 * @param array  $plugin_data Plugin Data.
				 */
				do_action( 'wpmudev_dashboard_after_generic_row_content', $filename, $plugin_data );
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Called on update-core.php after the list of available updates is printed.
	 * We use this opportunty to inset javascript to modify the update-list
	 * since there are no exising hooks in WP to do this on PHP side:
	 *
	 * Some plugins/themes might not support auto-update. Those items must be
	 * disabled here!
	 *
	 * @since  4.1.0
	 */
	public function modify_core_updates_page() {
		$is_logged_in = WPMUDEV_Dashboard::$api->has_key();
		$allowed_user = WPMUDEV_Dashboard::$site->allowed_user();
		$projects     = WPMUDEV_Dashboard::$site->get_cached_projects();

		$plugins = array();
		foreach ( $projects as $pid => $data ) {
			// Get project info.
			$item = WPMUDEV_Dashboard::$site->get_project_info( $pid );
			if ( ! $item || empty( $item->pid ) ) {
				continue;
			}

			if ( 'plugin' === $item->type ) {
				$action_html = '';
				// If Dash is not connected.
				if ( ! $is_logged_in ) {
					/* translators: %s link to dashboard. */
					$action_html = sprintf( __( '<a href="%s">Login to WPMU DEV Dashboard</a> to update', 'wpmudev' ), esc_url( $this->page_urls->dashboard_url ) );
				} elseif ( ! $allowed_user ) {
					// If auto update is disabled.
					$action_html = __( 'Auto-update not possible.', 'wpmudev' );
					if ( ! empty( $item->url->infos ) ) {
						/* translators: %s link to dashboard. */
						$action_html = $action_html . ' ' . sprintf( __( '<a href="%s">More info &raquo;</a>', 'wpmudev' ), esc_url( $item->url->infos ) );
					}
				} elseif ( ! $item->is_compatible && ! empty( $item->incompatible_reason ) ) {
					// If auto update is disabled.
					/* translators: %s: Incompatible reason. */
					$action_html = sprintf( __( 'Update not possible: %s.', 'wpmudev' ), $item->incompatible_reason );
					if ( ! empty( $item->url->infos ) ) {
						/* translators: %s link to dashboard. */
						$action_html = $action_html . ' ' . sprintf( __( '<a href="%s">More info &raquo;</a>', 'wpmudev' ), esc_url( $item->url->infos ) );
					}
				}

				// Set plugin data.
				$plugins[] = array(
					'pid'         => $item->pid,
					'file'        => $item->filename,
					'name'        => $item->name,
					'disabled'    => ! $item->can_update || ! $item->can_autoupdate || ! $item->is_compatible,
					'action_html' => empty( $action_html ) ? '' : '<div class="wpmudev-info" style="font-style: italic;">' . $action_html . '</div>',
				);
			}
		}

		if ( ! empty( $plugins ) ) {
			// Enqueue assets.
			wp_enqueue_script( 'wpmudev-dashboard-changelog' );

			// Localized vars.
			wp_localize_script(
				'wpmudev-dashboard-changelog',
				'wpmudevDashboard',
				array( 'plugins' => $plugins )
			);
		}
	}

	/**
	 * Setup the analytics dashboard widgets.
	 *
	 * Setup analytics charts and graphs for admin dashboard.
	 *
	 * @since    4.6
	 * @return void
	 * @uses     wp_add_dashboard_widget
	 *
	 * @internal Action hook
	 */
	public function analytics_widget_setup() {
		// Only if required.
		if ( $this->can_show_analytics_widget() ) {
			if ( is_blog_admin() && WPMUDEV_Dashboard::$site->user_can_analytics() ) {
				wp_add_dashboard_widget(
					'wdpun_analytics',
					__( 'Analytics', 'wpmudev' ),
					array( $this, 'render_analytics_widget' )
				);
			}

			// For network admin.
			if ( is_network_admin() ) {
				wp_add_dashboard_widget(
					'wdpun_analytics_network',
					__( 'Network Analytics', 'wpmudev' ),
					array( $this, 'render_analytics_widget' )
				);
			}
		}
	}

	/**
	 * Setup the analytics dashboard widgets assets.
	 *
	 * Enqueue style and scripts required on analytics widget.
	 *
	 * @since    4.11.3
	 * @internal Action hook
	 * @uses     $wp_locale
	 */
	public function analytics_widget_assets() {
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			// Continue only for WP admin dashboard page.
			if ( ! isset( $screen->id ) || ! in_array( $screen->id, array( 'dashboard', 'dashboard-network' ), true ) ) {
				return;
			}
			if ( 'dashboard' === $screen->id ) {
				if ( ! WPMUDEV_Dashboard::$site->user_can_analytics() ) {
					return;
				}
			}
		} else {
			// unable to identify current screen, bail to avoid leaks.
			return;
		}

		// Only if required.
		if ( $this->can_show_analytics_widget() ) {
			// Beta-testers will not have cached scripts!
			// Just in case we have to update the plugin prior to launch.
			$script_version = defined( 'WPMUDEV_BETATEST' ) && WPMUDEV_BETATEST ? time() : WPMUDEV_Dashboard::$version;

			// Enqueue styles.
			wp_enqueue_style(
				'wpmudev-widget-analytics',
				WPMUDEV_Dashboard::$site->plugin_url . 'build/analytics-widget/index.css',
				array(),
				$script_version
			);

			// Our custom script.
			wp_enqueue_script(
				'wpmudev-dashboard-widget',
				WPMUDEV_Dashboard::$site->plugin_url . 'build/analytics-widget/index.js',
				array( 'react', 'react-dom', 'wp-element', 'wp-i18n', 'jquery', 'jquery-ui-widget', 'jquery-ui-autocomplete' ),
				$script_version,
				true
			);

			// Translation strings.
			wp_add_inline_script( 'wpmudev-dashboard-widget', $this->load_json_localization_inline_script(), 'before' );

			// Localize dashboardSettings for the analytics widget React app.
			wp_localize_script(
				'wpmudev-dashboard-widget',
				'dashboardSettings',
				$this->get_analytics_widget_settings()
			);
		}
	}

	/**
	 * Check if analytics widget can be shown.
	 *
	 * @since 4.11.6
	 *
	 * @return bool
	 */
	private function can_show_analytics_widget() {
		return (
			WPMUDEV_Dashboard::$api->is_analytics_allowed() // Only if analytics allowed.
			&& WPMUDEV_Dashboard::$settings->get( 'enabled', 'analytics' ) // Only if analytics enabled.
		);
	}

	/**
	 * Get's a list of tags for given project type. Used for search or dropdowns.
	 *
	 * @since  1.0.0
	 *
	 * @param string $type [plugin|theme].
	 *
	 * @return array
	 */
	public function tags_data( $type ) {
		$res  = array();
		$data = WPMUDEV_Dashboard::$api->get_projects_data();

		if ( 'plugin' === $type ) {
			if ( isset( $data['plugin_tags'] ) ) {
				$tags = (array) $data['plugin_tags'];

				$known_tags = array(
					32  => 'Business',
					50  => 'SEO',
					498 => 'Marketing',
					31  => 'Publishing',
					29  => 'Community',
					489 => 'BuddyPress',
					16  => 'Multisite',
				);
				/**
				 * Keep tag labels untranslated before `init`.
				 *
				 * Some plugins read the `update_plugins` site transient before `init`.
				 * That invokes our `site_transient_update_plugins` filter, which can reach
				 * `filter_plugin_update_count()` and then `tags_data()` through project-info
				 * construction. Calling `__()` at that point can trigger WordPress's
				 * just-in-time textdomain-loading notice.
				 *
				 * Translate labels during or after `init`; use English fallback labels before
				 * then so early update checks remain functional.
				 *
				 * @see _load_textdomain_just_in_time
				 * @see http://incsub.atlassian.net/browse/WDD-714 ( WPConsent Pro/Premium )
				 */
				if ( did_action( 'init' ) ) {
					$known_tags = array(
						32  => __( 'Business', 'wpmudev' ),
						50  => __( 'SEO', 'wpmudev' ),
						498 => __( 'Marketing', 'wpmudev' ),
						31  => __( 'Publishing', 'wpmudev' ),
						29  => __( 'Community', 'wpmudev' ),
						489 => __( 'BuddyPress', 'wpmudev' ),
						16  => __( 'Multisite', 'wpmudev' ),
					);
				}

				// Important: Index 0 is "All", added automatically.
				$tag_index = 1;
				foreach ( $known_tags as $tag_id => $tag_name ) {
					if ( ! isset( $tags[ $tag_id ] ) ) {
						continue;
					}
					$res[ $tag_index ] = array(
						'name' => $tag_name,
						'pids' => (array) $tags[ $tag_id ]['pids'],
					);
					++$tag_index;
				}
			}
		} elseif ( 'theme' === $type ) {
			if ( isset( $data['theme_tags'] ) ) {
				$res = (array) $data['theme_tags'];
			}
		}

		return $res;
	}

	/**
	 * Redirect to the specified URL, even after page output already started.
	 *
	 * @since  4.0.0
	 *
	 * @param string $url The URL.
	 */
	public function redirect_to( $url ) {
		if ( headers_sent() ) {
			printf(
				'<script>window.location.href="%s";</script>',
				esc_url_raw( $url )
			); // wpcs xss ok.
		} else {
			header( 'X-Redirect-From: UI redirect_to' );
			wp_safe_redirect( $url );
		}
		exit;
	}

	/**
	 * Add link to WPMU DEV Dashboard to the WP toolbar; only for multisite
	 * networks, since single-site admins always see the WPMU DEV menu item.
	 *
	 * @since  4.1.0
	 *
	 * @param WP_Admin_Bar $wp_admin_bar The toolbar handler object.
	 */
	public function setup_toolbar( $wp_admin_bar ) {
		if ( is_multisite() ) {
			$args = array(
				'id'     => 'network-admin-d2',
				'title'  => 'WPMU DEV Dashboard',
				'href'   => $this->page_urls->dashboard_url,
				'parent' => 'network-admin',
			);

			$wp_admin_bar->add_node( $args );
		}
	}

	/**
	 * Add WPMUDEV link as return action after installing DEV plugins.
	 *
	 * Default actions are "Return to Themes/Plugins" and "Return to WP Updates"
	 * This filter adds a "Return to WPMUDEV Updates"
	 *
	 * @since    1.0.0
	 *
	 * @param array  $install_actions Array of further actions to display.
	 * @param object $api             The update API details.
	 *
	 * @return array
	 * @internal Action hook
	 */
	public function branding_install_plugin_done( $install_actions, $api ) {
		if ( ! empty( $api->download_link ) ) {
			if ( WPMUDEV_Dashboard::$api->is_server_url( $api->download_link ) ) {
				$install_actions['plugins_page'] = sprintf(
					'<a href="%s" title="%s" target="_parent">%s</a>',
					$this->page_urls->plugins_url,
					esc_attr__( 'Return to WPMU DEV Plugins', 'wpmudev' ),
					__( 'Return to WPMU DEV Plugins', 'wpmudev' )
				);
			}
		}

		return $install_actions;
	}

	/**
	 * Add WPMUDEV link as return action after upgrading DEV plugins.
	 *
	 * Default actions are "Return to Themes/Plugins" and "Return to WP Updates"
	 * This filter adds a "Return to WPMUDEV Updates"
	 *
	 * @since    1.0.0
	 *
	 * @param array  $update_actions Array of further actions to display.
	 * @param string $plugin         Main plugin file.
	 *
	 * @return array
	 * @internal Action hook
	 */
	public function branding_update_plugin_done( $update_actions, $plugin ) {
		$updates = get_site_transient( 'update_plugins' );

		if ( false !== $updates && ! empty( $updates->response[ $plugin ] ) ) {
			if ( WPMUDEV_Dashboard::$api->is_server_url( $updates->response[ $plugin ]->package ) ) {
				$update_actions['plugins_page'] = sprintf(
					'<a href="%s" title="%s" target="_parent">%s</a>',
					$this->page_urls->plugins_url,
					esc_attr__( 'Return to WPMU DEV Plugins', 'wpmudev' ),
					__( 'Return to WPMU DEV Plugins', 'wpmudev' )
				);
			}
		}

		return $update_actions;
	}

	/**
	 * Enqueue Dashboard styles on all non-dashboard admin pages.
	 *
	 * @since    1.0.0
	 * @internal Action hook
	 */
	public function notification_styles() {
		echo '<style>#toplevel_page_wpmudev .wdev-access-granted { font-size: 14px; line-height: 13px; height: 13px; float: right; color: #1ABC9C; }</style>';
	}

	/**
	 * Initialize react pages
	 *
	 * @since 5.0.0
	 */
	public function page_content() {
		?>
		<div id="sui-wrap">
			<div class="<?php echo esc_attr( $this->page_container ) . ' ' . esc_attr( $this->sui_classes ); ?>" id="<?php echo esc_attr( $this->page_container ); ?>"></div>
		</div>
		<?php
		// '#wpbody-content .dashui-onboarding form.js-wpmudev-login-form input.input-auth-nonce
		// auto installer compat: https://incsub.atlassian.net/browse/WDD-578
		?>
		<?php if ( ! WPMUDEV_Dashboard::$api->has_key() ) : ?>
			<div class="dashui-onboarding" style="display: none;">
				<form onsubmit="return;" class="js-wpmudev-login-form">
					<input class="input-auth-nonce" value="<?php echo esc_attr( wp_create_nonce( 'auth_nonce' ) ); ?>" type="hidden"/>>
				</form>
			</div>
		<?php endif; ?>

		<?php
		/**
		 * Action hook to render something after SUI footer.
		 *
		 * @since 5.0.0
		 */
		do_action( 'wpmudev_dashboard_ui_after_footer' );
	}

	/**
	 * Register the WPMUDEV Dashboard menu structure for the React implementation
	 *
	 * @since    1.0.0
	 * @internal Action hook
	 */
	public function setup_menu() {
		$is_logged_in   = WPMUDEV_Dashboard::$api->has_key();
		$count_output   = '';
		$remote_granted = false;
		$update_plugins = 0;
		$_get_data      = $_GET; // phpcs:ignore

		// Redirect user, if we have a valid PID in URL param.
		if ( ! empty( $_get_data['page'] ) && 0 === strpos( $_get_data['page'], 'wpmudev' ) ) {
			if ( ! empty( $_get_data['pid'] ) && is_numeric( $_get_data['pid'] ) ) {
				$project = WPMUDEV_Dashboard::$site->get_project_info( $_get_data['pid'] );
				if ( $project && ! empty( $project->pid ) ) {
					if ( 'plugin' === $project->type ) {
						// Install action if required.
						if ( ! empty( $_get_data['action'] ) && 'install' === $_get_data['action'] && $project->can_update ) {
							$redirect = $this->page_urls->plugins_url . '#install-pid=' . $project->pid;
						} else {
							$redirect = $this->page_urls->plugins_url . '#pid=' . $project->pid;
						}
						WPMUDEV_Dashboard::$ui->redirect_to( $redirect );
					}
				}
			}
		}

		if ( $is_logged_in ) {
			$data = WPMUDEV_Dashboard::$api->get_projects_data();
			// Show total number of available updates.
			$updates = WPMUDEV_Dashboard::$settings->get( 'updates_available' );
			if ( is_array( $updates ) ) {
				foreach ( $updates as $id => $item ) {
					if ( 'plugin' === $item['type'] ) {
						// Skip addons.
						if ( ! empty( $data['projects'][ $id ]['is_plugin_addon'] ) ) {
							continue;
						}

						++$update_plugins;
					}
				}
				$count = $update_plugins;

				if ( $count > 0 ) {
					$count_output = sprintf(
						'<span class="countval">%s</span>',
						$count
					);
				}
				$count_label   = array();
				$count_label[] = sprintf(
				/* translators: %s: Number of plugin updates. */
					_n( '%s Plugin update', '%s Plugin updates', $update_plugins, 'wpmudev' ),
					$update_plugins
				);

				$count_output = sprintf(
					' <span class="update-plugins total-updates count-%s" title="%s">%s</span>',
					$count,
					implode( ', ', $count_label ),
					$count_output
				);

				$staff_login    = WPMUDEV_Dashboard::$api->remote_access_details();
				$remote_granted = $staff_login->enabled ?? false;
			}
		} else {
			// Show icon if user is not logged in.
			$count_output = sprintf(
				' <span style="float:right;margin:-1px 13px 0 0;vertical-align:top;border-radius:10px;background:#F8F8F8;width:18px;height:18px;text-align:center" title="%s">%s</span>',
				__( 'Log in to your WPMU DEV account to use all features!', 'wpmudev' ),
				'<i class="dashicons dashicons-lock" style="font-size:14px;width:auto;line-height:18px;color:#333"></i>'
			);
		}

		$need_cap = 'manage_options'; // Single site.
		if ( is_multisite() ) {
			$need_cap = 'manage_network_options'; // Multi site.
		}

		// Dashboard Main Menu.
		$page = add_menu_page(
			__( 'WPMU DEV Dashboard', 'wpmudev' ),
			__( 'WPMU DEV', 'wpmudev' ) . $count_output,
			$need_cap,
			'wpmudev',
			array( $this, 'page_content' ),
			$this->get_menu_icon(),
			defined( 'WPMUDEV_MENU_LOCATION' ) ? WPMUDEV_MENU_LOCATION : null
		);

		add_action( 'load-' . $page, array( $this, 'load_admin_scripts' ) );

		$this->add_submenu(
			'wpmudev',
			__( 'WPMU DEV Dashboard', 'wpmudev' ),
			__( 'Dashboard', 'wpmudev' ),
			array( $this, 'page_content' ),
			$need_cap
		);

		if ( $is_logged_in ) {
			$membership_type       = WPMUDEV_Dashboard::$api->get_membership_status();
			$is_wpmudev_host       = WPMUDEV_Dashboard::$api->is_wpmu_dev_hosting();
			$is_standalone_hosting = WPMUDEV_Dashboard::$api->is_standalone_hosting_plan();
			$has_hosted_access     = $is_wpmudev_host && ! $is_standalone_hosting && 'free' === $membership_type;

			if ( WPMUDEV_Dashboard::$utils->can_access_feature( 'plugins' ) || $has_hosted_access ) {
				/**
				 * Use this action to register custom sub-menu items.
				 *
				 * The action is called before each of the default submenu items
				 * is registered, so other plugins can hook into any position they
				 * like by checking the action parameter.
				 *
				 * @param WPMUDEV_Dashboard_ui $instance Use $instance->add_submenu() to register new menu items.
				 * @param string               $menu     The menu-item that is about to be set up.
				 */
				do_action( 'wpmudev_dashboard_setup_menu', $this, 'plugins' );

				$plugin_badge = sprintf(
					' <span class="update-plugins plugin-updates wdev-update-count count-%1$s" data-count="%1$s"><span class="countval">%1$s</span></span>',
					$update_plugins
				);
				// Plugins page.
				$this->add_submenu(
					'plugins',
					__( 'WPMU DEV Plugins', 'wpmudev' ),
					__( 'Plugins', 'wpmudev' ) . $plugin_badge,
					array( $this, 'page_content' ),
					'install_plugins'
				);
			}

			if ( WPMUDEV_Dashboard::$utils->can_access_feature( 'support' ) || $has_hosted_access ) {
				do_action( 'wpmudev_dashboard_setup_menu', 'support' );

				// Support page.
				$support_icon = '';
				if ( $remote_granted ) {
					$support_icon = sprintf(
						' <i class="dashicons dashicons-unlock wdev-access-granted" title="%s"></i>',
						__( 'Support Access enabled', 'wpmudev' )
					);
				}
				$this->add_submenu(
					'support',
					__( 'WPMU DEV Support', 'wpmudev' ),
					__( 'Support', 'wpmudev' ) . $support_icon,
					array( $this, 'page_content' ),
					$need_cap
				);
			}

			do_action( 'wpmudev_dashboard_setup_menu', 'analytics' );
			$this->add_submenu(
				'analytics',
				__( 'WPMU DEV Analytics', 'wpmudev' ),
				__( 'Analytics', 'wpmudev' ),
				array( $this, 'page_content' ),
				$need_cap
			);

			if ( WPMUDEV_Dashboard::$utils->can_access_feature( 'whitelabel' ) ) {
				do_action( 'wpmudev_dashboard_setup_menu', 'whitelabel' );
				$this->add_submenu(
					'whitelabel',
					__( 'WPMU DEV Whitelabel', 'wpmudev' ),
					__( 'White Label', 'wpmudev' ),
					array( $this, 'page_content' ),
					$need_cap
				);
			}

			// Manage (Settings).
			do_action( 'wpmudev_dashboard_setup_menu', 'settings' );
			$this->add_submenu(
				'settings',
				__( 'WPMU DEV Settings', 'wpmudev' ),
				__( 'Settings', 'wpmudev' ),
				array( $this, 'page_content' ),
				$need_cap
			);

			do_action( 'wpmudev_dashboard_setup_menu', 'end' );
		}
	}

	/**
	 * Returns a base64 encoded SVG image that is used as Dashboard menu icon.
	 *
	 * Source image is file includes/images/logo.svg
	 * The source file is included with the plugin but not used.
	 *
	 * @since  4.0.0
	 * @return string Base64 encoded icon.
	 */
	protected function get_menu_icon() {
		ob_start();
		echo '<?xml version="1.0" encoding="UTF-8" standalone="no"?>';
		?>
		<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
			<path
					d="M1.91282 3.91087C1.63883 4.37887 1.49602 4.91528 1.50009 5.4611L1.50009 17.4622L3.70066 17.4622L3.70066 5.45906C3.70041 5.36562 3.71895 5.27313 3.75514 5.18736C3.79133 5.1016 3.84439 5.02441 3.91099 4.96063C4.06577 4.82307 4.26374 4.74734 4.46857 4.74734C4.67341 4.74734 4.87138 4.82307 5.02616 4.96063C5.09221 5.02474 5.14477 5.10204 5.1806 5.18776C5.21643 5.27347 5.23477 5.36581 5.2345 5.45906L5.2345 14.496C5.22425 14.8878 5.29258 15.2776 5.43525 15.6411C5.57793 16.0047 5.79191 16.3344 6.06393 16.6098C6.46926 17.0329 6.98871 17.3222 7.55557 17.4403C8.12243 17.5585 8.71081 17.5003 9.24513 17.273C9.77945 17.0458 10.2353 16.66 10.5542 16.1652C10.873 15.6703 11.0403 15.0891 11.0346 14.496L11.0346 5.45906C11.033 5.36654 11.0498 5.27465 11.0839 5.18898C11.118 5.1033 11.1687 5.02561 11.233 4.96063C11.3071 4.88829 11.3946 4.83207 11.4905 4.79536C11.5863 4.75865 11.6884 4.7422 11.7906 4.74701C11.8928 4.74148 11.9951 4.75759 12.091 4.79434C12.187 4.83109 12.2745 4.88769 12.3482 4.96063C12.4148 5.02441 12.4678 5.1016 12.504 5.18736C12.5402 5.27313 12.5587 5.36562 12.5585 5.45906L12.5585 14.496C12.5483 14.8876 12.6165 15.2772 12.7588 15.6407C12.9011 16.0042 13.1146 16.334 13.3859 16.6098C13.6579 16.8898 13.9828 17.1099 14.3408 17.2565C14.6987 17.4031 15.0821 17.4731 15.4674 17.4622C16.2593 17.4718 17.0239 17.1662 17.6005 16.6098C17.8904 16.345 18.1209 16.0189 18.2761 15.654C18.4313 15.2891 18.5075 14.894 18.4994 14.496L18.4994 2.50303L16.2969 2.50303L16.2969 14.496C16.2984 14.5885 16.2816 14.6804 16.2475 14.7661C16.2134 14.8518 16.1627 14.9295 16.0984 14.9944C15.9437 15.132 15.7457 15.2077 15.5409 15.2077C15.336 15.2077 15.1381 15.132 14.9833 14.9944C14.917 14.9304 14.8641 14.8532 14.8279 14.7675C14.7918 14.6818 14.773 14.5894 14.7729 14.496L14.7729 5.45906C14.7829 5.06751 14.7146 4.67801 14.5723 4.3145C14.43 3.951 14.2167 3.62117 13.9455 3.34529C13.6739 3.06817 13.3502 2.85045 12.9942 2.70533C12.6381 2.56021 12.257 2.49069 11.8739 2.501C11.0807 2.48839 10.3134 2.79094 9.73287 3.34529C9.44323 3.61043 9.21281 3.93653 9.05734 4.30133C8.90187 4.66613 8.825 5.06103 8.83201 5.45906L8.83201 14.496C8.81366 14.6717 8.73258 14.8343 8.60437 14.9524C8.47617 15.0705 8.30988 15.1359 8.13751 15.1359C7.96514 15.1359 7.79885 15.0705 7.67065 14.9524C7.54244 14.8343 7.46136 14.6717 7.44301 14.496L7.44301 5.45906C7.44762 4.91289 7.30403 4.37616 7.0283 3.90883C6.76827 3.45456 6.38493 3.08769 5.92504 2.85296C5.47483 2.62153 4.97815 2.50102 4.47453 2.50102C3.9709 2.50102 3.47423 2.62153 3.02402 2.85296C2.56072 3.08665 2.1744 3.45445 1.91282 3.91087Z"
					fill="#A7AAAD"
			/>
		</svg>
		<?php
		$svg = ob_get_clean();

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );//phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Official way to add new submenu items to the WPMUDEV Dashboard.
	 *
	 * The Dashboard styles are automatically enqueued for the new page.
	 *
	 * @since 4.0.0
	 *
	 * @param string   $id         The ID is prefixed with 'wpmudev-' for the page body class.
	 * @param string   $title      The documents title-tag.
	 * @param string   $label      The menu label.
	 * @param callable $handler    Function that is executed to render page content.
	 * @param string   $capability Optional. Required capability. Default: manage_options.
	 *
	 * @return string Page hook_suffix of the new menu item.
	 */
	public function add_submenu( $id, $title, $label, $handler, $capability = 'manage_options' ) {
		static $registered = array();

		// Prevent duplicates of the same menu item.
		if ( isset( $registered[ $id ] ) ) {
			return '';
		}
		$registered[ $id ] = true;

		if ( false === strpos( $id, 'wpmudev' ) ) {
			$id = 'wpmudev-' . $id;
		}

		$page = add_submenu_page(
			'wpmudev',
			$title,
			$label,
			$capability,
			$id,
			$handler
		);

		add_action( 'load-' . $page, array( $this, 'load_admin_scripts' ) );

		return $page;
	}

	/**
	 * Get current module from screen.
	 *
	 * @return string
	 */
	public function get_current_screen_module() {
		$current_module = 'dashboard';

		// Find out what items to display in the search field.
		$screen = get_current_screen();

		if ( is_object( $screen ) ) {
			$base = $screen->base;

			switch ( true ) {
				case false !== strpos( $base, 'plugins' ):
					$current_module = 'plugins';
					break;

				case false !== strpos( $base, 'support' ):
					$current_module = 'support';
					break;
				case false !== strpos( $base, 'analytics' ):
					$current_module = 'analytics';
					break;
				case false !== strpos( $base, 'whitelabel' ):
					$current_module = 'whitelabel';
					break;
				case false !== strpos( $base, 'settings' ):
					$current_module = 'settings';
					break;
				default:
					break;
			}
		}

		$is_logged_in = WPMUDEV_Dashboard::$api->has_key();

		if ( 'dashboard' === $current_module && ! $is_logged_in ) {
			$current_module = 'login';
		}

		return $current_module;
	}

	/**
	 * Load admin scripts.
	 *
	 * @internal Action hook
	 */
	public function load_admin_scripts() {
		$script_version = WPMUDEV_Dashboard::$version;

		// For WordPress media library functionality.
		// Add condition to specifically load media library on whitelabel page.
		if ( 'whitelabel' === $this->get_current_screen_module() ) {
			wp_enqueue_media();
		}

		// Enqueue styles.
		wp_enqueue_style(
			'wpmudev-dashboard-admin',
			WPMUDEV_Dashboard::$site->plugin_url . 'build/admin/index.css',
			array(),
			$script_version
		);

		// Register scripts and it's translations.
		wp_register_script(
			'wpmudev-dashboard-admin',
			WPMUDEV_Dashboard::$site->plugin_url . 'build/admin/index.js',
			array( 'react', 'react-dom', 'wp-i18n', 'wp-element' ),
			$script_version,
			true
		);
		wp_add_inline_script( 'wpmudev-dashboard-admin', $this->load_json_localization_inline_script(), 'before' );
		wp_enqueue_script( 'wpmudev-dashboard-admin' );
		wp_localize_script( 'wpmudev-dashboard-admin', 'dashboardSettings', $this->get_dashboard_settings() );
	}

	/**
	 * Get PHP environment variables.
	 * This is shared between usual wp-admin and WP REST endpoints, it could behave differently depends where this function is called.
	 * For example `display_errors` might be overridden by `wp_debug_mode` in WP REST context.
	 *
	 * @since 5.0.0
	 *
	 * @param array|null $allowed_properties list of properties to return, pass `null` to return all properties.
	 *
	 * @return array
	 */
	public function get_php_vars( ?array $allowed_properties = null ): array {
		$vars                = array();
		$is_property_allowed = fn( $property ) => is_null( $allowed_properties ) || ( is_array( $allowed_properties ) && in_array( $property, $allowed_properties, true ) );

		if ( $is_property_allowed( 'Version' ) ) {
			$vars['Version'] = phpversion();
		}

		// php ini.
		$php_vars = array(
			'max_execution_time',
			'open_basedir',
			'memory_limit',
			'upload_max_filesize',
			'post_max_size',
			'display_errors',
			'log_errors',
			'track_errors',
			'session.auto_start',
			'session.cache_expire',
			'session.cache_limiter',
			'session.cookie_domain',
			'session.cookie_httponly',
			'session.cookie_lifetime',
			'session.cookie_path',
			'session.cookie_secure',
			'session.gc_divisor',
			'session.gc_maxlifetime',
			'session.gc_probability',
			'session.referer_check',
			'session.save_handler',
			'session.save_path',
			'session.serialize_handler',
			'session.use_cookies',
			'session.use_only_cookies',
		);

		foreach ( $php_vars as $setting ) {
			if ( $is_property_allowed( $setting ) ) {
				$vars[ $setting ] = ini_get( $setting );
			}
		}

		if ( $is_property_allowed( 'Error Reporting' ) ) {
			$levels = array();
			// phpcs:disable WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting
			// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting
			$error_reporting = error_reporting();
			// phpcs:enable WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting
			// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting

			$constants = array(
				'E_ERROR',
				'E_WARNING',
				'E_PARSE',
				'E_NOTICE',
				'E_CORE_ERROR',
				'E_CORE_WARNING',
				'E_COMPILE_ERROR',
				'E_COMPILE_WARNING',
				'E_USER_ERROR',
				'E_USER_WARNING',
				'E_USER_NOTICE',
				'E_RECOVERABLE_ERROR',
				'E_DEPRECATED',
				'E_USER_DEPRECATED',
				'E_ALL',
			);

			/**
			 * Only include E_STRICT below 8.4.
			 *
			 * @see https://www.php.net/manual/en/migration84.incompatible.php#migration84.incompatible.core.e-strict
			 * @see https://incsub.atlassian.net/browse/WDD-589
			 */
			if ( version_compare( PHP_VERSION, '8.4', '<' ) ) {
				$constants[] = 'E_STRICT';
			}

			foreach ( $constants as $level ) {
				if ( defined( $level ) ) {
					$c = constant( $level );
					if ( $error_reporting & $c ) {
						$levels[ $c ] = $level;
					}
				}
			}

			$vars['Error Reporting'] = $levels;
		}

		if ( $is_property_allowed( 'Extensions' ) ) {
			$extensions = get_loaded_extensions();
			natcasesort( $extensions );
			$vars['Extensions'] = $extensions;
		}

		return $vars;
	}

	/**
	 * Get features in UI
	 *
	 * @return array
	 */
	public function get_features(): array {
		$features = array(
			'plugins'      => false,
			'support'      => false,
			'whitelabel'   => false,
			'analytics'    => false,
			'translations' => false,
		);

		// not logged in.
		if ( ! WPMUDEV_Dashboard::$api->has_key() ) {
			return $features;
		}

		$membership_type       = WPMUDEV_Dashboard::$api->get_membership_status();
		$is_wpmudev_host       = WPMUDEV_Dashboard::$api->is_wpmu_dev_hosting();
		$is_standalone_hosting = WPMUDEV_Dashboard::$api->is_standalone_hosting_plan();
		$has_hosted_access     = $is_wpmudev_host && ! $is_standalone_hosting && 'free' === $membership_type;

		$features['plugins']      = WPMUDEV_Dashboard::$utils->can_access_feature( 'plugins' ) || $has_hosted_access;
		$features['support']      = WPMUDEV_Dashboard::$utils->can_access_feature( 'support' ) || $has_hosted_access;
		$features['analytics']    = WPMUDEV_Dashboard::$api->is_analytics_allowed();
		$features['whitelabel']   = WPMUDEV_Dashboard::$api->is_whitelabel_allowed() && WPMUDEV_Dashboard::$utils->can_access_feature( 'whitelabel' );
		$features['translations'] = WPMUDEV_Dashboard::$utils->can_access_feature( 'translations' );

		return $features;
	}

	/**
	 * Outputs the Analytics dashboard widget
	 *
	 * @since    4.6
	 * @internal Menu callback
	 */
	public function render_analytics_widget() {
		echo '<div id="wpmudui-analytics-app" class="dashboard-admin sui-wrap sui-theme--light"></div>';
	}

	/**
	 * Handle snapshot v4
	 *
	 * @since    4.9.1
	 *
	 * @param Array $res result of get_project_infos().
	 */
	public function handle_snapshot_v4( $res ) {
		$snap_v3  = WPMUDEV_Dashboard::$site->get_project_info( 257 );
		$snap_v4  = WPMUDEV_Dashboard::$site->get_project_info( 3760011 );
		$projects = $res['projects'];

		// Show default.
		if ( ( $snap_v3 && $snap_v4 && $snap_v3->is_installed && $snap_v4->is_installed ) || ! $snap_v3 || ! $snap_v4 ) {
			return $res;
		}

		// Show v3.
		if ( $snap_v3 && $snap_v3->is_installed && ( ! $snap_v4 || ! $snap_v4->is_installed ) ) {
			$projects['3760011']['type'] = 'alt_plugin';
		}

		// Show v4.
		if ( ( $snap_v4 ) && ( ! $snap_v3 || ! $snap_v3->is_installed ) ) {
			$projects['257']['type'] = 'alt_plugin';
		}

		$res['projects'] = $projects;

		return $res;
	}

	/**
	 * Load inline script for localization.
	 *
	 * @since 5.0.0
	 * @return string
	 */
	private function load_json_localization_inline_script(): string {
		$translations = get_translations_for_domain( 'wpmudev' );
		$locale       = array(
			'translation-revision-date' => $translations->headers['PO-Revision-Date'] ?? '',
			'domain'                    => 'messages',
			'generator'                 => $translations->headers['X-Generator'] ?? '',
			'locale_data'               => array(
				'messages' => array(
					'' => array(
						'domain'       => 'messages',
						'plural-forms' => $translations->headers['Plural-Forms'] ?? 'nplurals=2; plural=n > 1;',
					),
				),

			),
		);

		if ( isset( $translations->headers['Language'] ) && $translations->headers['Language'] ) {
			$locale['locale_data']['messages']['']['lang'] = $translations->headers['Language'];
		}

		foreach ( $translations->entries as $entry ) {
			$key                                       = $entry->context ? $entry->context . chr( 4 ) . $entry->singular : $entry->singular;
			$locale['locale_data']['messages'][ $key ] = array_filter(
				$entry->translations,
				function ( $translation ) {
					return null !== $translation;
				}
			);
		}

		/**
		 * Prepare UI translations.
		 *
		 * @see WP_Scripts::print_translations()
		 */
		$json_translations = wp_json_encode( $locale );

		return <<<JS
			( function( domain, translations ) {
				var localeData = translations.locale_data[ domain ] || translations.locale_data.messages
				localeData[""].domain = domain
				wp.i18n.setLocaleData( localeData, domain )
			} )( 'wpmudev', {$json_translations} )
		JS;
	}
}