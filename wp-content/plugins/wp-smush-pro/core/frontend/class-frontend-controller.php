<?php

namespace Smush\Core\Frontend;

use Smush\App\Admin;
use Smush\Core\Backups\Backups;
use Smush\Core\Configs;
use Smush\Core\Controller;
use Smush\Core\Core;
use Smush\Core\Helper;
use Smush\Core\Hub_Connector;
use Smush\Core\Membership\Membership;
use Smush\Core\Modules\Helpers\WhiteLabel;
use Smush\Core\Multisite_Utils;
use Smush\Core\Product_Analytics\Product_Analytics;
use Smush\Core\S3\WP_Offload_Media_Api;
use Smush\Core\Settings;
use Smush\Core\Stats\Global_Stats;
use WP_Smush;

/**
 * Frontend Controller
 *
 * Handles AJAX endpoints for frontend operations including polling and settings sync.
 */
class Frontend_Controller extends Controller {
	public const PAGE_DASHBOARD = 'smush';
	public const PAGE_LAZY_PRELOAD = 'smush-lazy-preload';
	public const PAGE_CDN = 'smush-cdn';
	public const PAGE_DIRECTORY = 'smush-directory';
	public const PAGE_SETTINGS = 'smush-settings';

	/**
	 * @var WhiteLabel
	 */
	private $whitelabel;

	private static $instance = null;
	/**
	 * @var Global_Stats
	 */
	private $global_stats;

	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new Frontend_Controller_Pro();
		}

		return self::$instance;
	}

	public function __construct() {
		$this->whitelabel   = new WhiteLabel();
		$this->global_stats = Global_Stats::get();

		// Register AJAX endpoint for logged-in users
		$this->register_action( 'wp_ajax_smush_frontend_poll', array( $this, 'ajax_frontend_poll' ) );

		// Register unified settings sync endpoint
		$this->register_action( 'wp_ajax_smush_sync_settings', array( $this, 'ajax_sync_settings' ) );

		$this->register_action( 'wp_ajax_smush_ui_error', array( $this, 'ajax_ui_error' ) );

		// Hide the new features modal.
		$this->register_action( 'wp_ajax_hide_new_features', array( $this, 'hide_new_features_modal' ) );

		// Handle skip quick setup action.
		$this->register_action( 'wp_ajax_skip_smush_setup', array( $this, 'skip_smush_setup' ) );
		// Handle resume quick setup action.
		$this->register_action( 'wp_ajax_resume_smush_setup', array( $this, 'resume_smush_setup' ) );

		$this->register_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
		$this->register_action( 'admin_head', array( $this, 'print_pro_menu_badge_style' ) );
		$this->register_action( 'admin_head', array( $this, 'hide_admin_notices' ) );

		$this->register_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		$this->register_action( 'admin_enqueue_scripts', array( $this, 'enqueue_global_scripts' ) );

		// Run late so third-party callbacks that overwrite classes don't remove Smush classes.
		$this->register_filter( 'admin_body_class', array( $this, 'smush_body_classes' ), 9999 );
		$this->register_filter( 'admin_title', array( $this, 'smush_admin_title' ), 20, 2 );

		$this->register_filter( 'admin_footer_text', array( $this, 'smush_admin_footer_text' ) );
		$this->register_filter( 'update_footer', array( $this, 'smush_update_footer' ), 11, 1 );
		$this->register_action( 'admin_footer', array( $this, 'render_deactivate_survey_modal' ) );

		$this->register_filter( 'pre_load_script_translations', array( $this, 'provide_script_translations' ), 10, 4 );
		$this->register_filter( 'all_plugins', array( $this, 'maybe_whitelabel_plugin_name_in_plugins_list' ) );
		$this->register_filter( 'plugin_action_links_' . WP_SMUSH_BASENAME, array( $this, 'plugin_action_links' ) );
		$this->register_filter( 'network_admin_plugin_action_links_' . WP_SMUSH_BASENAME, array( $this, 'plugin_action_links' ) );
		$this->register_filter( 'plugin_row_meta', array( $this, 'add_plugin_meta_links' ), 10, 2 );

		// Handle the smush dismiss notice ajax.
		$this->register_action( 'wp_ajax_smush_dismiss_notice', array( $this, 'dismiss_notice' ) );
		// Hide API message. TODO: Check if we need to implement this, otherwise remove it.
		$this->register_action( 'wp_ajax_hide_api_message', array( $this, 'hide_api_message' ) );
	}

	/***************************************
	 *
	 * QUICK SETUP
	 */

	/**
	 * Process ajax action for skipping Smush setup.
	 */
	public function skip_smush_setup() {
		check_ajax_referer( 'smush_quick_setup' );
		// Check capability.
		if ( ! Helper::is_user_allowed( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-smushit' ), 403 );
		}
		update_option( 'skip-smush-setup', true );
		wp_send_json_success();
	}

	/**
	 * Process ajax action for resuming Smush setup.
	 */
	public function resume_smush_setup() {
		check_ajax_referer( 'wp-smush-ajax' );
		// Check capability.
		if ( ! Helper::is_user_allowed( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-smushit' ), 403 );
		}

		delete_option( 'skip-smush-setup' );
		wp_send_json_success();
	}

	/**
	 * Hide admin footer text only on Smush pages.
	 *
	 * @param string $footer_text Existing footer text.
	 *
	 * @return string
	 */
	public function smush_admin_footer_text( $footer_text ) {
		return $this->is_smush_admin_page() ? '' : $footer_text;
	}

	/**
	 * Hide WordPress version footer only on Smush pages.
	 *
	 * @param string $footer_version Existing footer version text.
	 *
	 * @return string
	 */
	public function smush_update_footer( $footer_version ) {
		return $this->is_smush_admin_page() ? '' : $footer_version;
	}

	/**
	 * Whether current page belongs to Smush admin.
	 *
	 * @return bool
	 */
	private function is_smush_admin_page() {
		$page = $this->get_current_page();
		if ( empty( $page ) ) {
			return false;
		}

		$smush_pages = array( self::PAGE_DASHBOARD );
		foreach ( $this->get_admin_pages() as $admin_page ) {
			$smush_pages[] = $admin_page->get_slug();
		}

		return in_array( $page, $smush_pages, true );
	}

	/**
	 * Prefix Smush admin browser titles with "Smush -".
	 *
	 * Keeps the original page name and site suffix intact, and skips prefixing
	 * when white label branding is enabled.
	 *
	 * @param string $admin_title Full admin title string (e.g. "Page < Site").
	 * @param string $title Current page title.
	 *
	 * @return string
	 */
	public function smush_admin_title( $admin_title, $title ) {
		if ( false !== strpos( $title, 'Smush - ' ) || ! $this->is_smush_admin_page() ) {
			return $admin_title;
		}

		// translators: %s is the admin page title (e.g. "Dashboard", "Settings").
		return $this->whitelabel->is_whitelabel_enabled() ? $title : sprintf( esc_html__( 'Smush - %s', 'wp-smushit' ), $title );
	}

	/**
	 * Handle frontend poll AJAX request
	 *
	 * @return void
	 */
	public function ajax_frontend_poll() {
		// Verify nonce for security
		$is_nonce_valid  = check_ajax_referer( 'wp-smush-ajax', '_ajax_nonce', false );
		$is_user_allowed = $this->user_can_make_ajax_call();
		if ( ! $is_nonce_valid || ! $is_user_allowed ) {
			wp_send_json_error(
				array(
					'error_msg' => esc_html__( 'Security verification failed.', 'wp-smushit' ),
				)
			);
		}

		// Get polling data from filters
		$polling_data = apply_filters( 'wp_smush_frontend_poll_data', array() );

		// Return the polling data
		wp_send_json_success( $polling_data );
	}

	/**
	 * Whether the user can make an ajax call
	 */
	public function user_can_make_ajax_call() {
		return Helper::is_user_allowed();
	}

	/**
	 * Whether the user can sync settings for a specific context
	 */
	public function user_can_sync_settings_for_context( $context ) {
		return true;
	}

	public function user_can_see_menu_page( $page ) {
		return true;
	}

	public function user_can_see_submenu_page( $page ) {
		return true;
	}

	public function get_permission_level_for_menus() {
		return 'manage_options';
	}

	protected function filter_settings_before_sync( $settings ) {
		return $settings;
	}

	/**
	 * Unified settings sync endpoint
	 *
	 * Handles saving settings for all features via a common endpoint.
	 * Features hook into 'wp_smush_sync_settings' action to handle their own settings.
	 *
	 * @return void
	 * @since 3.25.0
	 */
	public function ajax_sync_settings() {
		// Use the same nonce action as the rest of Smush AJAX handlers.
		check_ajax_referer( 'wp-smush-ajax' );

		if ( ! $this->user_can_make_ajax_call() ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'wp-smushit' ) ), 403 );
		}

		$raw_settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : '';
		$settings     = is_string( $raw_settings ) ? json_decode( $raw_settings, true ) : $raw_settings;
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$context = isset( $_POST['context'] ) ? sanitize_text_field( $_POST['context'] ) : '';
		if ( ! $this->user_can_sync_settings_for_context( $context ) ) {
			wp_send_json_error( array(
				'message' => __( 'Insufficient permissions', 'wp-smushit' ),
			) );
		}

		$settings = $this->filter_settings_before_sync( $settings );

		/**
		 * Filter to allow features to save their settings.
		 *
		 * Features should check the context and return the saved settings array if it matches their context.
		 * Return null or empty array if the context doesn't match.
		 *
		 * @param array|null $saved_settings Saved settings array (camelCase), or null if not handled.
		 * @param array $settings Incoming settings from React (camelCase).
		 * @param string $context Context identifier (e.g., 'cdn', 'lazyload', 'nextgen', 'preload', 'site').
		 */
		$saved_settings = apply_filters( 'wp_smush_sync_settings', null, $settings, $context );

		// If no settings were saved (null or empty), something went wrong
		if ( empty( $saved_settings ) && ! empty( $settings ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No handler found for the provided settings context', 'wp-smushit' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'message'    => __( 'Settings saved successfully', 'wp-smushit' ),
				'settings'   => $saved_settings,
				'isOutdated' => $this->global_stats->is_outdated(),
			)
		);
	}

	public function add_menu_pages() {
		$pages = $this->get_admin_pages();

		$is_pro     = 'wp-smush-pro/wp-smush.php' === WP_SMUSH_BASENAME;
		$page_title = $is_pro ? esc_html__( 'Smush Pro', 'wp-smushit' ) : esc_html__( 'Smush', 'wp-smushit' );
		$menu_title = $is_pro
			? sprintf(
				'%1$s<span class="smush-admin-menu-pro-tag">%2$s</span>',
				esc_html__( 'Smush', 'wp-smushit' ),
				esc_html__( 'Pro', 'wp-smushit' )
			)
			: esc_html__( 'Smush', 'wp-smushit' );

		if ( ! $this->user_can_see_menu_page( self::PAGE_DASHBOARD ) ) {
			return;
		}

		add_menu_page(
			$page_title,
			$menu_title,
			$this->get_permission_level_for_menus(),
			self::PAGE_DASHBOARD,
			null,
			$this->get_menu_icon()
		);

		foreach ( $pages as $page ) {
			$slug = $page->get_slug();

			if ( $this->user_can_see_submenu_page( $slug ) ) {
				add_submenu_page(
					self::PAGE_DASHBOARD,
					$page->get_title(),
					$page->get_title(),
					$this->get_permission_level_for_menus(),
					$slug,
					function () {
						?>
						<div id="wpmudev-plugin-ui-smush-page-root"></div>
						<?php
					}
				);
			}
		}

		$this->add_upgrade_submenu_page();
	}

	public function hide_admin_notices() {
		// TODO: [WPMUDEV SMUSH UI] revisit
		?>
		<style>
			body.toplevel_page_smush .notice,
			body[class*="smush"] .notice {
				display: none;
			}

			body.toplevel_page_smush .notice.update-nag,
			body[class*="smush"] .notice.update-nag {
				display: inherit;
			}
		</style>
		<?php
	}

	/**
	 * Print custom styles for the Pro badge in the admin menu.
	 *
	 * @return void
	 */
	public function print_pro_menu_badge_style() {
		$is_pro = ( 'wp-smush-pro/wp-smush.php' === WP_SMUSH_BASENAME );
		?>
		<style>
			<?php if ( $is_pro ) : ?>
			#adminmenu .smush-admin-menu-pro-tag {
				display: inline-block;
				padding: 0;
				color: inherit;
				border: 1px solid currentColor;
				border-radius: 9px;
				line-height: 16px;
				font-size: 11px;
				height: 16px;
				width: 28px;
				text-align: center;
				margin-left: 5px;
			}

			[dir="rtl"] #adminmenu .smush-admin-menu-pro-tag {
				margin-left: 0;
				margin-right: 5px;
			}

			<?php endif; ?>
		</style>
		<?php

		$this->print_upgrade_submenu_script();
	}

	public function enqueue_scripts() {
		wp_register_script( 'smush-ui-vendor', WP_SMUSH_URL . 'app/assets/js/smush-ui-vendor.min.js', array( 'lodash' ), WP_SMUSH_VERSION, true );

		$pages        = $this->get_admin_pages();
		$current_page = $this->get_current_page();

		if ( empty( $current_page ) ) {
			return;
		}

		// Enqueue WordPress media library scripts so wp.media is available.
		wp_enqueue_media();

		foreach ( $pages as $page ) {
			if ( $page->get_slug() !== $current_page ) {
				continue;
			}

			if ( $this->show_onboarding_wizard() ) {
				$this->enqueue_onboarding_wizard_script( $page );
				return;
			}

			$styles = $page->get_styles();
			foreach ( $styles as $style ) {
				wp_enqueue_style(
					$style->get_handle(),
					$style->get_source(),
					$style->get_dependencies(),
					$style->get_version(),
					$style->get_media()
				);
			}

			$scripts = $page->get_scripts();
			foreach ( $scripts as $script ) {
				wp_enqueue_script(
					$script->get_handle(),
					$script->get_source(),
					$script->get_dependencies(),
					$script->get_version(),
					$script->get_in_footer()
				);

				wp_set_script_translations( $script->get_handle(), 'wp-smushit', WP_SMUSH_DIR . 'languages' );

				$this->localize_script( $script->get_handle(), $script->get_localization_data(), $page );
			}

			break;
		}
	}

	/**
	 * Build the global data array passed to every page's localised script.
	 *
	 * Override in a subclass to add or replace top-level keys (e.g. inject
	 * `permissionsData` from the multisite subclass).
	 *
	 * @param Admin_Page $page
	 *
	 * @return array
	 */
	protected function get_global_data( $page ) {
		$membership        = Membership::get_instance();
		$update_data       = $this->get_update_notification_data();
		$is_multisite      = is_multisite();
		$smush_deactivated = is_super_admin() && (bool) get_site_option( 'smush_deactivated' );
		if ( $smush_deactivated ) {
			delete_site_option( 'smush_deactivated' );
		}

		return array(
			'isMultisite'         => $is_multisite,
			'isNetworkAdmin'      => $is_multisite && is_network_admin(),
			'canManageNetwork'    => $is_multisite && Multisite_Utils::can_manage_network(),
			'isWpmudevHost'       => isset( $_SERVER['WPMUDEV_HOSTED'] ),
			'showUpgradeModal'    => $this->should_show_upgrade_modal(),
			'dismissedNotices'    => $this->get_dismissed_notices(),
			'profileData'         => array(
				// The initials are not "Personally Identifiable Information" (PII).
				'initials'               => $this->get_user_initials(),
				'avatar'                 => $this->get_user_avatar(),
				'profileBackgroundColor' => '#0059ff',
				'profileFontColor'       => '#ffffff',
				'email'                  => $this->get_user_email(),
				'displayName'            => $this->get_user_display_name(),
			),
			'pageUrls'            => $this->get_page_urls(),
			'hideBranding'        => apply_filters( 'wpmudev_branding_hide_branding', false ),
			'isPro'               => $membership->is_pro(),
			'isProPlugin'         => 'wp-smush-pro/wp-smush.php' === WP_SMUSH_BASENAME,
			'dashboardActive'     => class_exists( 'WPMUDEV_Dashboard' ),
			'dashboardNoticeData' => $this->get_dashboard_notice_data( $membership ),
			'resetNonce'          => wp_create_nonce( 'wp_smush_reset' ),
			'updateNotification'  => $update_data,
			'metaData'            => array(
				'cdnPopLocations' => Admin::get_cdn_pop_locations(),
			),
			'wpAjax'              => array(
				'url'   => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'wp-smush-ajax' ),
			),
			'whiteLabel'          => $this->whitelabel->get_whitelabel_data(),
			'smushDeactivated'    => $smush_deactivated,
			's3NoticeData'        => $this->get_s3_notice_data(),
			'pluginConflictData'  => $this->get_plugin_conflict_data(),
		);
	}

	private function get_dismissed_notices() {
		$dismiss_key = 'wp-smush-dismissed-notices';

		$fallback_dismissed_notices = get_option( $dismiss_key, array() );
		$fallback_dismissed_notices = is_array( $fallback_dismissed_notices ) ? $fallback_dismissed_notices : array();

		$user_dismissed_notices = get_user_meta( get_current_user_id(), $dismiss_key, true );
		$user_dismissed_notices = is_array( $user_dismissed_notices ) ? $user_dismissed_notices : array();

		$dismissed_notices = array_merge( $fallback_dismissed_notices, $user_dismissed_notices );

		return array_keys( array_filter( $dismissed_notices ) );
	}

	private function get_plugin_conflict_data() {
		$conflict_plugins = get_transient( 'wp-smush-conflict-plugins' );

		if ( ! is_array( $conflict_plugins ) || empty( $conflict_plugins ) ) {
			return array();
		}

		$optimizations_plugins = isset( $conflict_plugins['optimization'] ) ? $conflict_plugins['optimization'] : array();
		$lazyload_plugins      = isset( $conflict_plugins['lazyload'] ) ? $conflict_plugins['lazyload'] : array();

		return array(
			// Re-index with array_values so the JSON output is an array, not an object.
			'plugins'         => array_values( array_unique( array_merge( $optimizations_plugins, $lazyload_plugins ) ) ),
			'lazyloadPlugins' => array_values( $lazyload_plugins ),
			'pluginsPageUrl'  => admin_url( 'plugins.php' ),
		);
	}

	private function localize_script( $handle, $data, $page ) {
		$page_slug = $page->get_slug();

		$script_data = array_merge(
			$this->get_global_data( $page ),
			$data
		);
		$script_data = apply_filters( 'wp_smush_localize_ui_script_data_' . str_replace( '-', '_', $page_slug ), $script_data );
		$script_data = apply_filters( 'wp_smush_localize_ui_script_data', $script_data, $page_slug );

		wp_localize_script( $handle, 'smushUIData', $script_data );
	}


	/**
	 * Enqueue global scripts for the Smush UI.
	 *
	 * @return void
	 */
	public function enqueue_global_scripts() {
		wp_register_script( 'smush-shared-ui', WP_SMUSH_URL . 'app/assets/js/smush-shared-ui.min.js', array(), WP_SMUSH_VERSION, true );
		wp_register_style( 'smush-shared-ui', WP_SMUSH_URL . 'app/assets/css/smush-shared-ui.min.css', array(), WP_SMUSH_VERSION );
		wp_enqueue_script( 'smush-global', WP_SMUSH_URL . 'app/assets/js/smush-global.min.js', array(), WP_SMUSH_VERSION, true );
		wp_localize_script(
			'smush-global',
			'smush_global',
			$this->get_global_localization()
		);

		wp_localize_script(
			'smush-global',
			'wp_smush_mixpanel',
			array(
				'opt_in' => Settings::get_instance()->get( 'usage' ),
			)
		);

		// Styles that can be used on all pages in the WP backend.
		wp_register_style( 'smush-global', WP_SMUSH_URL . 'app/assets/css/smush-global.min.css', array(), WP_SMUSH_VERSION );
		wp_enqueue_style( 'smush-global' );
	}

	/**
	 * Get global localization data for smush_global JS object.
	 *
	 * @return array
	 */
	protected function get_global_localization() {
		$data = array(
			// General AJAX nonce used by most Smush requests.
			'nonce'   => wp_create_nonce( 'wp-smush-ajax' ),
			// Dedicated nonce for unified settings sync endpoint (expects 'wp_smush_ajax').
			'strings' => array(
				'stats_label'          => $this->whitelabel->replace_branding_terms( esc_html__( 'Smush', 'wp-smushit' ) ),
				'filter_all'           => $this->whitelabel->replace_branding_terms( esc_html__( 'Smush: All images', 'wp-smushit' ) ),
				'filter_not_processed' => $this->whitelabel->replace_branding_terms( esc_html__( 'Smush: Not processed', 'wp-smushit' ) ),
				'filter_excl'          => $this->whitelabel->replace_branding_terms( esc_html__( 'Smush: Bulk ignored', 'wp-smushit' ) ),
				'filter_failed'        => $this->whitelabel->replace_branding_terms( esc_html__( 'Smush: Failed Processing', 'wp-smushit' ) ),
				'gb'                   => array(
					'stats'        => $this->whitelabel->replace_branding_terms( esc_html__( 'Smush Stats', 'wp-smushit' ) ),
					'select_image' => $this->whitelabel->replace_branding_terms( esc_html__( 'Select an image to view Smush stats.', 'wp-smushit' ) ),
					'size'         => esc_html__( 'Image size', 'wp-smushit' ),
					'savings'      => esc_html__( 'Savings', 'wp-smushit' ),
				),
			),
		);
		/**
		 * Filter the global localization data for smush_global JS object.
		 *
		 * @param array $data The localization data.
		 */
		return apply_filters( 'wp_smush_global_localization', $data );
	}

	/**
	 * Build the `pageUrls` block passed to every page's localised script.
	 *
	 * Override in a subclass to replace URLs that must point to the network
	 * admin area on multisite (e.g. `settingsUrl`, `nextGenUrl`).
	 *
	 * @return array
	 */
	protected function get_page_urls() {
		$settings_url = Helper::get_page_url( self::PAGE_SETTINGS );

		return array(
			'frontendUrl'     => home_url(),
			'mediaLibraryUrl' => admin_url( 'upload.php' ),
			'settingsUrl'     => $settings_url,
			'dashboardUrl'    => Helper::get_page_url( self::PAGE_DASHBOARD ),
			'lazyLoadUrl'     => Helper::get_page_url( self::PAGE_LAZY_PRELOAD ),
			'cdnUrl'          => Helper::get_page_url( self::PAGE_CDN ),
			'directoryUrl'    => Helper::get_page_url( self::PAGE_DIRECTORY ),
			'nextGenUrl'      => add_query_arg( array( 'view' => 'nextgen' ), $settings_url ),
			'integrationsUrl' => add_query_arg( array( 'view' => 'integrations' ), $settings_url ),
		);
	}

	/**
	 * Get update notification data for the UI.
	 *
	 * @return array
	 */
	private function get_update_notification_data() {
        $updates          = get_site_transient( 'update_plugins' );
        $hide_notice      = (bool)get_site_option( 'wp-smush-hide_update_info', false );
        $can_update_smush = current_user_can( 'update_plugins' );
        $has_update       = false;
        $latest_version   = '';
        $update_url       = $this->get_plugin_update_url();
        $changelog_url    = 'https://wpmudev.com/project/wp-smush-pro/#changelog_all';

		if ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ) {
			$plugin_update = isset( $updates->response[ WP_SMUSH_BASENAME ] ) ? $updates->response[ WP_SMUSH_BASENAME ] : null;
			if ( is_object( $plugin_update ) ) {
				$latest_version = isset( $plugin_update->new_version ) ? (string) $plugin_update->new_version : '';
				$has_update     = ! empty( $latest_version ) && version_compare( WP_SMUSH_VERSION, $latest_version, '<' );
			}
		}

		$release_date_raw = defined( 'WP_SMUSH_RELEASE_DATE' ) ? WP_SMUSH_RELEASE_DATE : '';
		$timestamp        = strtotime( $release_date_raw );
		$release_date     = false !== $timestamp
			? date_i18n( get_option( 'date_format' ), $timestamp )
			: '';

		$dashboard_active    = class_exists( 'WPMUDEV_Dashboard' );
		$dashboard_installed = $dashboard_active || file_exists( WP_PLUGIN_DIR . '/wpmudev-updates/update-notifications.php' );
		return array(
			'hasUpdate'            => $has_update,
			'currentVersion'       => WP_SMUSH_VERSION,
			'latestVersion'        => $latest_version,
			'shouldShow'           => $has_update && $can_update_smush,
			'updateUrl'            => $update_url,
			'changelogUrl'         => $changelog_url,
			'releaseDate'          => $release_date,
			'isDashboardActive'    => $dashboard_active,
			'isDashboardInstalled' => $dashboard_installed,
		);
	}

	private function get_dashboard_notice_data( $membership ) {
		$dashboard_installed   = is_dir( WP_PLUGIN_DIR . '/wpmudev-updates' );
		$install_dashboard_url = $this->get_install_dashboard_url();

		return array(
			'dashboardInstalled'  => $dashboard_installed,
			'dashboardPageUrl'    => is_multisite()
				? network_admin_url( 'admin.php?page=wpmudev' )
				: admin_url( 'admin.php?page=wpmudev' ),
			'activeDashboardUrl'  => $this->get_active_dashboard_url( $dashboard_installed ),
			'installDashboardUrl' => $install_dashboard_url,
		);
	}

	private function get_active_dashboard_url( $dashboard_installed ) {
		if ( $dashboard_installed ) {
			$plugin_basename = 'wpmudev-updates/update-notifications.php';
			$base_url        = is_multisite()
				? network_admin_url( 'plugins.php?action=activate&plugin=' . $plugin_basename )
				: admin_url( 'plugins.php?action=activate&plugin=' . $plugin_basename );
			$action          = 'activate-plugin_' . $plugin_basename;
			return add_query_arg( '_wpnonce', wp_create_nonce( $action ), $base_url );
		}

		return is_multisite() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
	}

	private function get_install_dashboard_url() {
		if ( class_exists( 'WPMUDEV_Dashboard_Notice' ) ) {
			$action_url = is_multisite()
				? network_admin_url( 'update.php?action=install-plugin&plugin=install_wpmudev_dash' )
				: admin_url( 'update.php?action=install-plugin&plugin=install_wpmudev_dash' );
			$action     = 'install-plugin_install_wpmudev_dash';
			return add_query_arg( '_wpnonce', wp_create_nonce( $action ), $action_url );
		}

		return 'https://wpmudev.com/project/wpmu-dev-dashboard/';
	}

	private function get_s3_notice_data() {
		$wp_offload_media  = new WP_Offload_Media_Api();
		$wp_offload_active = function_exists( 'as3cf_init' ) || function_exists( 'as3cf_pro_init' );
		$is_s3_active      = Settings::get_instance()->is_s3_active();
		$can_interact      = $wp_offload_active
		                     && $wp_offload_media->is_plugin_setup() !== null
		                     && $wp_offload_media->get_plugin_page_url() !== null;
		$integrations_url  = add_query_arg( array( 'view' => 'integrations' ), Helper::get_page_url( self::PAGE_SETTINGS ) );

		return array(
			'wpOffloadMediaActive'     => $wp_offload_active,
			'isS3Active'               => $is_s3_active,
			'canInteract'              => $can_interact,
			'isPluginSetup'            => $can_interact ? (bool) $wp_offload_media->is_plugin_setup() : null,
			'pluginPageUrl'            => $can_interact ? $wp_offload_media->get_plugin_page_url() : null,
			'isSupportNoticeDismissed' => ! empty( get_option( 'wp-smush-dismissed-notices', array() )['s3_support_notice'] ),
			'integrationsUrl'          => $integrations_url,
		);
	}

	private function get_menu_icon() {
		ob_start();
		?>
		<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
			<path d="M8.00078 0.799805C4.04078 0.799805 0.800781 4.0398 0.800781 7.9998C0.800781 11.9598 4.04078 15.1998 8.00078 15.1998C11.9608 15.1998 15.2008 11.9598 15.2008 7.9998C15.2008 4.0398 11.9608 0.799805 8.00078 0.799805ZM8.00078 13.7598C6.84878 13.7598 5.84078 12.7518 5.84078 11.5998C5.84078 10.4478 6.84878 9.4398 8.00078 9.4398C9.15278 9.4398 10.1608 10.4478 10.1608 11.5998C10.1608 12.7518 9.15278 13.7598 8.00078 13.7598ZM11.8888 10.5918C11.8168 10.8798 11.7448 11.1678 11.6008 11.4558C11.5288 10.5198 11.1688 9.7278 10.5208 9.0798C9.87278 8.3598 8.93678 7.9998 8.00078 7.9998C7.28078 7.9998 6.56078 8.2158 5.98478 8.5758C5.40878 8.9358 4.90478 9.5118 4.68878 10.1598C4.54478 10.5918 4.40078 11.0238 4.40078 11.4558C4.18478 10.9518 4.04078 10.3758 4.04078 9.7998C4.04078 8.7198 4.47278 7.7118 5.19278 6.9918C5.91278 6.2718 6.92078 5.8398 8.00078 5.8398C8.79278 5.8398 9.58478 6.0558 10.2328 6.4878C10.8808 6.9198 11.3848 7.5678 11.6728 8.2878C11.9608 9.0078 12.0328 9.7998 11.8888 10.5918ZM13.3288 10.0158C13.3288 9.9438 13.3288 9.8718 13.3288 9.7998C13.3288 8.3598 12.7528 6.9918 11.7448 5.9838C10.7368 4.9758 9.44078 4.3998 8.00078 4.3998C6.92078 4.3998 5.91278 4.6878 4.97678 5.3358C4.11278 5.9118 3.39278 6.7758 2.96078 7.7838C2.67278 8.5038 2.52878 9.2958 2.52878 10.0878C2.38478 9.3678 2.24078 8.7198 2.24078 7.9998C2.24078 6.4878 2.81678 4.9758 3.89678 3.8958C4.97678 2.8158 6.48878 2.2398 8.00078 2.2398C9.15278 2.2398 10.2328 2.5998 11.1688 3.1758C12.1048 3.8238 12.8248 4.6878 13.2568 5.7678C13.7608 6.8478 13.9048 7.9998 13.6168 9.1518C13.5448 9.43981 13.4728 9.7278 13.3288 10.0158Z"
			      fill="#fff"/>
		</svg>
		<?php
		$svg = ob_get_clean();

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * @return Admin_Page[]
	 */
	protected function get_admin_pages() {
		$pages = array();

		// Dashboard page
		$pages[] = $this->create_dashboard_page();

		// Lazy Load & Preload page
		$pages[] = $this->create_lazy_preload_page();

		// CDN page
		$pages[] = $this->create_cdn_page();

		// Directory page
		$pages[] = $this->create_directory_page();

		// Settings page
		$pages[] = $this->create_settings_page();

		return apply_filters( 'wp_smush_pages', $pages );
	}

	private function create_dashboard_page() {
		$script = ( new Script() )
			->set_handle( 'smush-ui-dashboard' )
			->set_source( WP_SMUSH_URL . 'app/assets/js/smush-ui-dashboard.min.js' )
			->set_dependencies( array( 'smush-ui-vendor', 'wp-i18n', 'media-editor' ) )
			->set_version( WP_SMUSH_VERSION )
			->set_in_footer( true )
			->set_localization_data( array() );

		$style = ( new Style() )
			->set_handle( 'smush-ui-dashboard-style' )
			->set_source( WP_SMUSH_URL . 'app/assets/css/smush-ui-dashboard.min.css' )
			->set_dependencies( array() )
			->set_version( WP_SMUSH_VERSION );

		return ( new Admin_Page() )
			->set_slug( self::PAGE_DASHBOARD )
			->set_title( __( 'Dashboard', 'wp-smushit' ) )
			->set_scripts( array( $script ) )
			->set_styles( array( $style ) );
	}

	private function create_lazy_preload_page() {
		$script = ( new Script() )
			->set_handle( 'smush-ui-lazy-preload' )
			->set_source( WP_SMUSH_URL . 'app/assets/js/smush-ui-lazy-preload.min.js' )
			->set_dependencies( array( 'smush-ui-vendor', 'wp-i18n', 'media-editor' ) )
			->set_version( WP_SMUSH_VERSION )
			->set_in_footer( true )
			->set_localization_data( array() );

		$style = ( new Style() )
			->set_handle( 'smush-ui-lazy-preload' )
			->set_source( WP_SMUSH_URL . 'app/assets/css/smush-ui-lazy-preload.min.css' )
			->set_dependencies( array() )
			->set_version( WP_SMUSH_VERSION );

		return ( new Admin_Page() )
			->set_slug( self::PAGE_LAZY_PRELOAD )
			->set_title( __( 'Lazy Load & Preload', 'wp-smushit' ) )
			->set_scripts( array( $script ) )
			->set_styles( array( $style ) );
	}

	private function create_cdn_page() {
		$script = ( new Script() )
			->set_handle( 'smush-ui-cdn' )
			->set_source( WP_SMUSH_URL . 'app/assets/js/smush-ui-cdn.min.js' )
			->set_dependencies( array( 'smush-ui-vendor', 'wp-i18n', 'media-editor' ) )
			->set_version( WP_SMUSH_VERSION )
			->set_in_footer( true )
			->set_localization_data( array() );

		$style = ( new Style() )
			->set_handle( 'smush-ui-cdn' )
			->set_source( WP_SMUSH_URL . 'app/assets/css/smush-ui-cdn.min.css' )
			->set_dependencies( array() )
			->set_version( WP_SMUSH_VERSION );

		return ( new Admin_Page() )
			->set_slug( self::PAGE_CDN )
			->set_title( __( 'CDN', 'wp-smushit' ) )
			->set_scripts( array( $script ) )
			->set_styles( array( $style ) );
	}

	private function create_directory_page() {
		$stats    = \WP_Smush::get_instance()->core()->mod->dir->total_stats();
		$dir_list = \WP_Smush::get_instance()->core()->mod->dir->get_directory_list();

		$script = ( new Script() )
			->set_handle( 'smush-ui-directory' )
			->set_source( WP_SMUSH_URL . 'app/assets/js/smush-ui-directory-smush.min.js' )
			->set_dependencies( array( 'smush-ui-vendor', 'wp-i18n', 'media-editor' ) )
			->set_version( WP_SMUSH_VERSION )
			->set_in_footer( true )
			->set_localization_data(
				array(
					// This data is only used for the directory page, so we can keep it here for now.
					// but if we need to add more data for other pages, we can move it to the global data.
					'requestsData' => array(
						'directoryStats' => array(
							'dirList' => $dir_list,
						),
					),
				)
			);

		$style = ( new Style() )
			->set_handle( 'smush-ui-directory-style' )
			->set_source( WP_SMUSH_URL . 'app/assets/css/smush-ui-directory-smush.min.css' )
			->set_dependencies( array() )
			->set_version( WP_SMUSH_VERSION );

		return ( new Admin_Page() )
			->set_slug( self::PAGE_DIRECTORY )
			->set_title( $this->whitelabel->is_whitelabel_enabled() ? '<span style="white-space: nowrap">' . __( 'Directory Optimization', 'wp-smushit' ) . '</span>' : __( 'Directory Smush', 'wp-smushit' ) )
			->set_scripts( array( $script ) )
			->set_styles( array( $style ) );
	}

	private function create_settings_page() {
		// Get current tab from URL (view parameter)
		$tabs = array(
			'lazyload'      => __( 'Lazyload', 'wp-smushit' ),
			'preload'       => __( 'Preload', 'wp-smushit' ),
			'cdn'           => __( 'CDN', 'wp-smushit' ),
			'nextgen'       => __( 'Next-Gen Formats', 'wp-smushit' ),
			'integrations'  => __( 'Integrations', 'wp-smushit' ),
			'general'       => __( 'General', 'wp-smushit' ),
			'data-settings' => __( 'Data & Settings', 'wp-smushit' ),
			'accessibility' => __( 'Accessibility', 'wp-smushit' ),
		);

		$current_tab = filter_input( INPUT_GET, 'view', FILTER_SANITIZE_SPECIAL_CHARS );
		if ( ! $current_tab || ! array_key_exists( $current_tab, $tabs ) ) {
			reset( $tabs );
			$current_tab = key( $tabs );
		}
		$core         = WP_Smush::get_instance()->core();
		$sizes        = $core->image_dimensions();
		$image_sizes  = Settings::get_instance()->get_setting( 'wp-smush-image_sizes' );
		$all_selected = ! is_array( $image_sizes ) || count( $image_sizes ) === count( $sizes );
		$event_times  = get_site_option( 'wp_smush_event_times', array() );
		$configs      = Configs::get_instance()->get_callback();
		$installed_at = is_array( $event_times ) && ! empty( $event_times['plugin_installed'] )
			? $event_times['plugin_installed']
			: '';

		if ( $installed_at && is_array( $configs ) ) {
			foreach ( $configs as $index => $config ) {
				if ( ! empty( $config['default'] ) && empty( $config['date'] ) ) {
					$configs[ $index ]['date'] = $installed_at;
				}
			}
		}

		$restore_ids = $this->get_restore_ids();

		$script = ( new Script() )
			->set_handle( 'smush-ui-settings' )
			->set_source( WP_SMUSH_URL . 'app/assets/js/smush-ui-settings.min.js' )
			->set_dependencies( array( 'smush-ui-vendor', 'wp-i18n', 'media-editor', 'clipboard' ) )
			->set_version( WP_SMUSH_VERSION )
			->set_in_footer( true )
			->set_localization_data(
				array(
					'activeSettingsTab' => $current_tab,
					'links'             => array(),
					'requestsData'      => array(
						'ImageRestoreData' => array(
							'restore_ids'        => $restore_ids,
							'smush_bulk_restore' => wp_create_nonce( 'smush_bulk_restore' ),
						),
					),
					'bulkSmushMetaData' => array(
						'imageSizes' => array(
							'allSelected' => $all_selected,
							'sizes'       => $sizes,
							'selected'    => is_array( $image_sizes ) ? $image_sizes : array(),
						),
					),
					'configsData'       => array(
						'nonce'               => wp_create_nonce( 'smush_handle_config' ),
						'restNonce'           => wp_create_nonce( 'wp_rest' ),
						'restUrl'             => rest_url( 'wp-smush/v1/preset_configs' ),
						// Initial configs list (avoids a separate REST fetch on page load).
						'configs'             => $configs,
						'configTipsDismissed' => ! empty( get_option( 'wp-smush-dismissed-notices', array() )['config_tips_toast'] ),
					),
				)
			);

		$style = ( new Style() )
			->set_handle( 'smush-ui-settings-style' )
			->set_source( WP_SMUSH_URL . 'app/assets/css/smush-ui-settings.min.css' )
			->set_dependencies( array() )
			->set_version( WP_SMUSH_VERSION );

		return ( new Admin_Page() )
			->set_slug( self::PAGE_SETTINGS )
			->set_title( __( 'Settings', 'wp-smushit' ) )
			->set_scripts( array( $script ) )
			->set_styles( array( $style ) );
	}

	/**
	 * Get the attachment IDs that have a backup available for restoring.
	 *
	 * The restore list is only consumed by the settings page UI, so the backup
	 * lookup (a full postmeta scan) is skipped on every other wp-admin page.
	 *
	 * @return array
	 */
	private function get_restore_ids() {
		if ( self::PAGE_SETTINGS !== $this->get_current_page() ) {
			return array();
		}

		return ( new Backups() )->get_attachments_with_backups();
	}

	public function smush_body_classes( $classes ) {
		// Exit if function doesn't exists.
		if ( ! function_exists( 'get_current_screen' ) ) {
			return $classes;
		}

		$current_screen_id = get_current_screen()->id;

		// If not on plugin page.
		if ( ! in_array( $current_screen_id, Admin::$plugin_pages, true ) && false === strpos( $current_screen_id, 'page_smush' ) ) {
			return $classes;
		}

		// Remove old wpmud class from body of smush page to avoid style conflict.
		$classes = str_replace( 'wpmud ', '', $classes );

		$classes .= ' wpmudev-core-ui';

		if ( $this->show_onboarding_wizard() ) {
			$classes .= ' smush-onboarding-wizard smush-onboarding-wizard--fullscreen';
		}

		if ( Settings::get_instance()->get( 'accessible_colors' ) ) {
			$classes .= ' wpmudev-core-ui__mono';
		}

		$classes .= Membership::get_instance()->get_guest_value( ' smush-plan-free', ' smush-plan-pro' );

		return $classes;
	}

	/**
	 * Get user avatar URL.
	 *
	 * @return string|bool Avatar URL or false on failure.
	 * @since 3.24.0
	 */
	protected function get_user_avatar() {
		$current_user = wp_get_current_user();

		if ( ! $this->has_real_gravatar( $current_user->user_email ) ) {
			return false;
		}

		$avatar_url = get_avatar_url( $current_user->ID );

		return $avatar_url;
	}

	/**
	 * Checks if the current user has a real Gravatar image set.
	 * * @return bool True if they have a custom photo, false if they are using a default.
	 */
	protected function has_real_gravatar( $user_email = null ) {
		$email        = trim( strtolower( $user_email ) );
		$hash         = md5( $email );
		$cache_key    = 'smush_user_gravatar_' . $hash;
		$has_gravatar = get_transient( $cache_key );

		if ( $has_gravatar ) {
			return (bool) $has_gravatar;
		}

		// Build a URL that asks Gravatar to return a 404 error if no image exists.
		$check_url = "https://www.gravatar.com/avatar/$hash?d=404";

		// Use WordPress remote_head to check just the headers (and save some bandwidth).
		$response = wp_remote_head( $check_url );

		// If the response code is 200, the image exists. If it is 404, it doesn't.
		$has_gravatar = wp_remote_retrieve_response_code( $response ) === 200;
		set_transient( $cache_key, $has_gravatar, DAY_IN_SECONDS );

		return $has_gravatar;
	}

	/**
	 * Get user initials from first and last name.
	 *
	 * @return string User initials.
	 * @since 3.24.0
	 */
	protected function get_user_initials() {
		$current_user = wp_get_current_user();

		$first = $current_user->user_firstname;
		$last  = $current_user->user_lastname;

		if ( ! empty( $first ) || ! empty( $last ) ) {
			$first_initial = ! empty( $first ) ? substr( $first, 0, 1 ) : '';
			$last_initial  = ! empty( $last ) ? substr( $last, 0, 1 ) : '';
			return strtoupper( $first_initial . $last_initial );
		}

		// Fallback: Use the first letter of the Display Name if no first or last name is set.
		return strtoupper( substr( $current_user->display_name, 0, 1 ) );
	}

	/**
	 * Get current user email address.
	 *
	 * @return string User email or empty string if not available.
	 * @since 3.24.0
	 */
	protected function get_user_email() {
		$current_user = wp_get_current_user();

		if ( ! $current_user->exists() ) {
			return '';
		}

		return $current_user->user_email ?? '';
	}

	/**
	 * Get current user display name.
	 *
	 * @return string User display name or empty string if not available.
	 * @since 4.0
	 */
	protected function get_user_display_name() {
		$current_user = wp_get_current_user();

		if ( ! $current_user->exists() ) {
			return '';
		}

		return $current_user->display_name ?? '';
	}

	private function show_onboarding_wizard() {
		if ( is_multisite() && ! is_network_admin() ) {
			return false;
		}
		$skip_quick_setup   = ! empty( get_option( 'skip-smush-setup' ) );
		$smush_action       = isset( $_GET['smush_action'] ) ? sanitize_text_field( $_GET['smush_action'] ) : '';
		$should_skip_wizard = Hub_Connector::is_syncing() || ( 'skip-wizard' === $smush_action );
		if ( $should_skip_wizard ) {
			if ( ! $skip_quick_setup ) {
				// If syncing, and not skipped, we consider the setup is done.
				update_option( 'skip-smush-setup', true );
			}

			return false;
		}

		$is_our_page = false;
		foreach ( $this->get_admin_pages() as $admin_page ) {
			if ( $admin_page->get_slug() === $this->get_current_page() ) {
				$is_our_page = true;
				break;
			}
		}
		if ( ! $is_our_page ) {
			return false;
		}

		// Keep users on the login flow when Hub returns an auth error.
		if ( Hub_Connector::has_error_in_login() ) {
			return true;
		}

		$settings = Settings::get_instance();
		if (
			$skip_quick_setup
			|| ! $settings->has_bulk_smush_page()
		) {
			return false;
		}

		return true;
	}

	private function enqueue_onboarding_wizard_script( $page ) {
		wp_enqueue_script(
			'smush-ui-onboarding-wizard',
			WP_SMUSH_URL . 'app/assets/js/smush-ui-onboarding-wizard.min.js',
			array( 'smush-ui-vendor', 'wp-i18n' ),
			WP_SMUSH_VERSION,
			true
		);

		wp_set_script_translations( 'smush-ui-onboarding-wizard', 'wp-smushit', WP_SMUSH_DIR . 'languages' );

		$this->localize_script(
			'smush-ui-onboarding-wizard',
			array(
				'smushSetupNonce'      => wp_create_nonce( 'smush_quick_setup' ),
				'showOnboardingWizard' => true,
			),
			$page
		);

		wp_enqueue_style(
			'smush-ui-onboarding-wizard-style',
			WP_SMUSH_URL . 'app/assets/css/smush-ui-onboarding-wizard.min.css',
			array(),
			WP_SMUSH_VERSION
		);
	}

	/**
	 * @return string
	 */
	private function get_plugin_update_url() {
		return add_query_arg(
			array(
				'action'   => 'upgrade-plugin',
				'plugin'   => WP_SMUSH_BASENAME,
				'_wpnonce' => wp_create_nonce( 'upgrade-plugin_' . WP_SMUSH_BASENAME ),
			),
			self_admin_url( 'update.php' )
		);
	}

	private function get_current_page() {
		return isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
	}

	/**
	 * Handle a React UI error event and forward it to product analytics.
	 *
	 * Accepts:
	 *   message         (string) – error.message
	 *   code            (string) – error.name (e.g. "TypeError")
	 *   stack           (string) – error.stack, truncated to 2000 chars by the client
	 *   component_stack (string) – React component hierarchy from ErrorInfo
	 *   page            (string) – window.location.pathname at the time of the error
	 *
	 * @return void
	 */
	public function ajax_ui_error() {
		check_ajax_referer( 'wp-smush-ajax' );

		if ( ! Helper::is_user_allowed() ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'wp-smushit' ) ), 403 );
		}

		$message         = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : '';
		$code            = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : 'UnknownError';
		$stack           = isset( $_POST['stack'] ) ? sanitize_textarea_field( wp_unslash( $_POST['stack'] ) ) : '';
		$component_stack = isset( $_POST['component_stack'] ) ? sanitize_textarea_field( wp_unslash( $_POST['component_stack'] ) ) : '';
		$page            = isset( $_POST['page'] ) ? sanitize_text_field( wp_unslash( $_POST['page'] ) ) : '';

		$extra_properties = array_filter( array(
			'Stack Trace'     => $stack,
			'Component Stack' => $component_stack,
			'Page'            => $page,
		) );

		Product_Analytics::get_instance()->maybe_track_error( 'react_ui', $code, $message, $extra_properties );

		wp_send_json_success();
	}

	/**
	 * Render the deactivation survey modal.
	 *
	 * @return void
	 */
	public function render_deactivate_survey_modal() {
		$current_screen = get_current_screen();
		if ( ! $current_screen || 'plugins' !== $current_screen->id ) {
			return;
		}

		wp_enqueue_script( 'smush-shared-ui' );
		wp_enqueue_style( 'smush-shared-ui' );

		$this->view( 'deactivation-survey' );
	}

	/**
	 * Load an admin view.
	 *
	 * @param string $name View name = file name.
	 * @param array $args Arguments.
	 * @param string $dir Directory for the views. Default: views.
	 */
	private function view( $name, $args = array(), $dir = 'views' ) {
		$file    = WP_SMUSH_DIR . "app/{$dir}/{$name}.php";
		$content = '';

		if ( is_file( $file ) ) {
			ob_start();

			if ( isset( $args['id'] ) ) {
				$args['orig_id'] = $args['id'];
				$args['id']      = str_replace( '/', '-', $args['id'] );
			}
			extract( $args );//phpcs:ignore

			include $file;

			$content = ob_get_clean();
		}

		// Everything escaped in all template files.
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Check if the upgrade modal should be shown.
	 *
	 * @return bool
	 */
	private function should_show_upgrade_modal() {
		return get_site_option( 'wp-smush-show_upgrade_modal' );
	}

	/**
	 * Hide the new features modal
	 */
	public function hide_new_features_modal() {
		check_ajax_referer( 'wp-smush-ajax' );

		// Check for permission.
		if ( ! Helper::is_user_allowed( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-smushit' ), 403 );
		}

		delete_site_option( 'wp-smush-show_upgrade_modal' );
		wp_send_json_success();
	}

	/**
	 * Provide translations for JavaScript files.
	 *
	 * @param mixed $translations
	 * @param mixed $file
	 * @param mixed $handle
	 * @param mixed $domain
	 */
	public function provide_script_translations( $translations, $file, $handle, $domain ) {
		if ( 'wp-smushit' !== $domain ) {
			return $translations;
		}

		static $served = false;
		static $cached_json = null;

		if ( $served ) {
			return wp_json_encode(
				array(
					'locale_data' => array(
						'wp-smushit' => array(
							'' => array(
								'domain' => 'wp-smushit',
							),
						),
					),
				)
			);
		}

		if ( null !== $cached_json ) {
			return $cached_json;
		}

		$locale  = is_admin() ? get_user_locale() : get_locale();
		$mo_file = WP_LANG_DIR . "/plugins/{$domain}-{$locale}.mo";

		if ( ! file_exists( $mo_file ) ) {
			return $translations;
		}

		$mo = new \MO();
		if ( ! $mo->import_from_file( $mo_file ) ) {
			return $translations;
		}

		$locale_data = array(
			'' => array(
				'domain' => $domain,
				'lang'   => $locale,
			),
		);

		if ( ! empty( $mo->headers['Plural-Forms'] ) ) {
			$locale_data['']['plural-forms'] = $mo->headers['Plural-Forms'];
		}

		foreach ( $mo->entries as $msgid => $entry ) {
			$locale_data[ $msgid ] = $entry->translations;
		}

		$cached_json = wp_json_encode(
			array(
				'locale_data' => array(
					$domain => $locale_data,
				),
			)
		);

		$served = true;
		return $cached_json;
	}

	/**
	 * White-label the plugin name as displayed on the Plugins screen (`plugins.php`).
	 *
	 * This only affects display (the plugin file/slug stays the same).
	 *
	 * @param array $plugins All plugins.
	 *
	 * @return array
	 */
	public function maybe_whitelabel_plugin_name_in_plugins_list( $plugins ) {
		if ( ! is_array( $plugins ) || ! defined( 'WP_SMUSH_BASENAME' ) || empty( $plugins[ WP_SMUSH_BASENAME ] ) ) {
			return $plugins;
		}

		if ( ! $this->whitelabel->is_whitelabel_enabled() ) {
			return $plugins;
		}

		$whitelabel_keys = array(
			'Name',
			'Title',
		);

		// Whitelabel the plugin name and title.
		foreach ( $whitelabel_keys as $key ) {
			if ( isset( $plugins[ WP_SMUSH_BASENAME ][ $key ] ) ) {
				$plugins[ WP_SMUSH_BASENAME ][ $key ] = $this->whitelabel->replace_branding_terms( $plugins[ WP_SMUSH_BASENAME ][ $key ] );
			}
		}

		// Whitelabel the plugin description.
		$whitelabel_description                      = $this->whitelabel->replace_branding_terms(
			$this->whitelabel->remove_brand_links( $plugins[ WP_SMUSH_BASENAME ]['Description'] )
		);
		$plugins[ WP_SMUSH_BASENAME ]['Description'] = $whitelabel_description;

		// Whitelabel the plugin author.
		if ( $this->whitelabel->hide_doc_link() ) {
			$plugins[ WP_SMUSH_BASENAME ]['Author']    = '';
			$plugins[ WP_SMUSH_BASENAME ]['PluginURI'] = '';
		}

		return $plugins;
	}


	/**
	 * Adds action links on plugin page.
	 *
	 * @param array $links Current links.
	 *
	 * @return array|string
	 */
	public function plugin_action_links( $links ) {
		// Upgrade link.
		if ( Membership::get_instance()->get_guest_value( true, false ) ) {
			$upgrade_url = add_query_arg(
				array(
					'utm_source'   => 'smush',
					'utm_medium'   => 'plugin',
					'utm_campaign' => 'wp-smush-pro/wp-smush.php' !== WP_SMUSH_BASENAME ? 'smush_pluginlist_upgrade' : 'smush_pluginlist_renew',
				),
				esc_url( 'https://wpmudev.com/project/wp-smush-pro/' )
			);

			$using_free_version = 'wp-smush-pro/wp-smush.php' !== WP_SMUSH_BASENAME;
			if ( $using_free_version ) {
				$label = __( 'Upgrade to Smush Pro', 'wp-smushit' );
				$text  = __( 'Get Smush Pro · Unlimited sites · $4.99/m', 'wp-smushit' );
			} else {
				$label = __( 'Renew Membership', 'wp-smushit' );
				$text  = __( 'Renew Membership', 'wp-smushit' );
			}

			if ( isset( $text ) ) {
				$links['smush_upgrade'] = '<a id="smush-pluginlist-upgrade-link" href="' . esc_url( $upgrade_url ) . '" aria-label="' . esc_attr( $label ) . '" target="_blank" style="color: #8D00B1;">' . esc_html( $text ) . '</a>';
			}
		}

		if ( ! $this->whitelabel->should_hide_doc_link() ) {
			// Documentation link.
			$docs_link           = Helper::get_utm_link(
				array( 'utm_campaign' => 'smush_pluginlist_docs' ),
				'https://wpmudev.com/docs/wpmu-dev-plugins/smush/'
			);
			$links['smush_docs'] = '<a href="' . esc_url( $docs_link ) . '" aria-label="' . esc_attr( __( 'View Smush Documentation', 'wp-smushit' ) ) . '" target="_blank">' . esc_html__( 'Docs', 'wp-smushit' ) . '</a>';
		}

		// Dashboard link.
		$dashboard_page           = is_multisite() && is_network_admin() ? network_admin_url( 'admin.php?page=smush' ) : menu_page_url( 'smush', false );
		$links['smush_dashboard'] = '<a href="' . esc_url( $dashboard_page ) . '" aria-label="' . esc_attr( $this->whitelabel->replace_branding_terms( __( 'Go to Smush Dashboard', 'wp-smushit' ) ) ) . '">' . esc_html__( 'Dashboard', 'wp-smushit' ) . '</a>';

		$access = get_site_option( 'wp-smush-networkwide' );
		if ( ! is_network_admin() && is_plugin_active_for_network( WP_SMUSH_BASENAME ) && ! $access ) {
			// Remove settings link for subsites if Subsite Controls is not set on network permissions tab.
			unset( $links['smush_dashboard'] );
		}

		return array_reverse( $links );
	}

	/**
	 * Add additional links next to the plugin version.
	 *
	 * @param array $links Links array.
	 * @param string $file Plugin basename.
	 *
	 * @return array
	 */
	public function add_plugin_meta_links( $links, $file ) {
		if ( ! defined( 'WP_SMUSH_BASENAME' ) || WP_SMUSH_BASENAME !== $file ) {
			return $links;
		}

		if ( 'wp-smush-pro/wp-smush.php' !== WP_SMUSH_BASENAME ) {
			$links[] = '<a href="https://wordpress.org/support/plugin/wp-smushit/reviews/?filter=5#new-post" target="_blank" title="' . esc_attr__( 'Rate Smush', 'wp-smushit' ) . '">' . esc_html__( 'Rate Smush', 'wp-smushit' ) . '</a>';
			$links[] = '<a href="https://wordpress.org/support/plugin/wp-smushit/" target="_blank" title="' . esc_attr__( 'Support', 'wp-smushit' ) . '">' . esc_html__( 'Support', 'wp-smushit' ) . '</a>';
		} elseif ( ! $this->whitelabel->should_hide_doc_link() ) {
			if ( isset( $links[2] ) && false !== strpos( $links[2], 'project/wp-smush-pro' ) ) {
				$links[2] = sprintf(
					'<a href="https://wpmudev.com/project/wp-smush-pro/" target="_blank">%s</a>',
					__( 'View details', 'wp-smushit' )
				);
			}

			$links[] = '<a href="https://wpmudev.com/get-support/" target="_blank" title="' . esc_attr__( 'Premium Support', 'wp-smushit' ) . '">' . esc_html__( 'Premium Support', 'wp-smushit' ) . '</a>';
		}

		if ( $this->whitelabel->should_hide_doc_link() ) {
			return $links;
		}

		$roadmap_link = Helper::get_utm_link(
			array(
				'utm_campaign' => 'smush_pluginlist_roadmap',
			),
			'https://wpmudev.com/roadmap/'
		);

		$links[] = '<a href="' . esc_url( $roadmap_link ) . '" target="_blank" title="' . esc_attr__( 'Roadmap', 'wp-smushit' ) . '">' . esc_html__( 'Roadmap', 'wp-smushit' ) . '</a>';
		$links[] = '<a class="wp-smush-review" href="https://wordpress.org/support/plugin/wp-smushit/reviews/?filter=5#new-post" target="_blank" rel="noopener noreferrer" title="' . esc_attr__( 'Rate our plugin', 'wp-smushit' ) . '">
					<span>★</span><span>★</span><span>★</span><span>★</span><span>★</span>
					</a>';

		echo '<style>.wp-smush-review span,.wp-smush-review span:hover{color:#ffb900}.wp-smush-review span:hover~span{color:#888}</style>';

		return $links;
	}

	/**
	 * Dismiss the plugin conflicts notice.
	 */
	public function dismiss_notice() {
		check_ajax_referer( 'wp-smush-ajax' );

		// Check capability.
		if ( ! Helper::is_user_allowed( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-smushit' ), 403 );
		}

		if ( empty( $_REQUEST['key'] ) ) {
			wp_send_json_error();
		}

		$use_user_meta = isset( $_REQUEST['user_meta'] ) && wp_validate_boolean( wp_unslash( $_REQUEST['user_meta'] ) );
		$this->set_notice_dismissed( sanitize_key( $_REQUEST['key'] ), $use_user_meta );

		wp_send_json_success();
	}

	private function set_notice_dismissed( $notice, $use_user_meta = false ) {
		$storage_key       = 'wp-smush-dismissed-notices';
		$dismissed_notices = $use_user_meta
			? get_user_meta( get_current_user_id(), $storage_key, true )
			: get_option( $storage_key, array() );
		$dismissed_notices = is_array( $dismissed_notices ) ? $dismissed_notices : array();
		$dismissed_notices[ $notice ] = true;

		if ( $use_user_meta ) {
			update_user_meta( get_current_user_id(), $storage_key, $dismissed_notices );
			return;
		}

		update_option( $storage_key, $dismissed_notices );
	}

	/**
	 * Hide API Message
	 */
	public function hide_api_message() {
		check_ajax_referer( 'wp-smush-ajax' );

		// Check capability.
		if ( ! Helper::is_user_allowed( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-smushit' ), 403 );
		}

		$api_message = get_site_option( 'wp-smush-api_message', array() );
		if ( ! empty( $api_message ) && is_array( $api_message ) ) {
			$api_message[ key( $api_message ) ]['status'] = 'hide';
			update_site_option( 'wp-smush-api_message', $api_message );
		}

		wp_send_json_success();
	}

	public function print_upgrade_submenu_script() {

	}

	/**
	 * @return void
	 */
	public function add_upgrade_submenu_page() {
		add_submenu_page(
			self::PAGE_DASHBOARD,
			__( 'Upgrade to Smush Pro', 'wp-smushit' ),
			sprintf(
				'<span class="smush-admin-menu-upgrade-title">%1$s</span><span class="smush-admin-menu-upgrade-pro-tag">%2$s</span><span class="smush-admin-menu-upgrade-icon" aria-hidden="true"></span><span class="smush-admin-menu-upgrade-subtitle">%3$s</span>',
				__( 'Upgrade', 'wp-smushit' ),
				__( 'Pro', 'wp-smushit' ),
				__( 'Unlimited sites · $4.99/m', 'wp-smushit' )
			),
			$this->get_permission_level_for_menus(),
			esc_url( 'https://wpmudev.com/project/wp-smush-pro/?utm_source=smush&utm_medium=plugin&utm_campaign=smush_new-submenu_upsell#dev-pricing' )
		);
	}
}