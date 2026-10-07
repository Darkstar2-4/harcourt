<?php

namespace Smush\Core\Frontend;

use Smush\Core\Array_Utils;
use Smush\Core\Helper;
use Smush\Core\Settings;

class Multisite_Frontend_Controller extends Frontend_Controller {

	private $subsite_controls_site_option;

	private $all_modules = array(
		'avif',
		'bulk',
		'cdn',
		'integrations',
		'lazy_load',
		'preload',
		'settings',
		'webp',
		'nextgen',
		'directory_smush',
		'permissions',
		'configs',
	);
	private $array_utils;

    private static $instance = null;

    public static function get_instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new Multisite_Frontend_Controller_Pro();
        }

        return self::$instance;
    }

	public function __construct() {
		parent::__construct();

		$this->array_utils = new Array_Utils();

		$this->register_action( 'network_admin_menu', array( $this, 'add_menu_pages' ) );
		$this->register_action( 'network_admin_head', array( $this, 'print_pro_menu_badge_style' ) );
		$this->register_action( 'network_admin_head', array( $this, 'hide_admin_notices' ) );
		$this->register_action( 'admin_head', array( $this, 'maybe_hide_dashboard' ) );
		$this->register_filter( 'wp_smush_localize_ui_script_data', array( $this, 'add_permissions_to_localized_data' ) );
		$this->register_filter( 'wp_smush_sync_settings', array( $this, 'handle_permissions_sync' ), 10, 3 );
		$this->register_action( 'admin_init', array( $this, 'maybe_redirect_dashboard' ) );
	}

	public function maybe_hide_dashboard() {
		if ( $this->should_hide_dashboard_page_on_subsite_admin() ) {
			?>
			<style>
				.toplevel_page_smush .wp-first-item {
					display: none;
				}
			</style>
			<?php
		}
	}

	private function is_current_page_hidden_subsite_dashboard() {
		$current_page = $this->array_utils->get_array_value( $_GET, 'page' );

		return $current_page === self::PAGE_DASHBOARD // dashboard page
		       && $this->should_hide_dashboard_page_on_subsite_admin();
	}

	private function should_hide_dashboard_page_on_subsite_admin() {
		return ! is_network_admin()
		       && ! $this->should_render_page( self::PAGE_DASHBOARD );
	}

	public function maybe_redirect_dashboard() {
		if ( $this->is_current_page_hidden_subsite_dashboard() ) {
			foreach ( $this->get_admin_pages() as $page ) {
				if ( $this->should_render_page( $page->get_slug() ) ) {
					wp_safe_redirect( Helper::get_page_url( $page->get_slug() ) );
					exit;
				}
			}
		}
	}

	public function can_current_user_access_module( $module ) {
		$is_network_admin = is_network_admin() || Settings::is_ajax_network_admin();

		return $is_network_admin
			? $this->can_current_user_access_module_on_network_admin()
			: $this->can_current_user_access_module_on_subsite_admin( $module );
	}

	public function can_current_user_access_module_on_network_admin() {
		return $this->current_user_can_manage_network();
	}

	public function can_current_user_access_module_on_subsite_admin( $module ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			// Definitely don't give access if the user can't manage a sub-site
			return false;
		}

		if ( $this->is_override_disabled() ) {
			return false;
		}

		$overrideable = array(
			'bulk',
			'directory_smush',
			'integrations',
			'lazy_load',
			'cdn',
		);
		// lazy_load covers preload as well
		$module          = $module === 'preload' ? 'lazy_load' : $module;
		$is_network_only = ! in_array( $module, $overrideable );
		if ( $is_network_only ) {
			return false;
		}

		if ( $this->is_full_override_enabled() ) {
			// We already know the user at least has manage_options, and this not one of the network only modules
			return true;
		} else {
			return $this->is_module_overridden( $module );
		}
	}

	/**
	 * Require manage_network for calls originating from the network admin area,
	 * manage_options for calls from a subsite or single-site admin.
	 *
	 * Context is detected via the HTTP_REFERER (Settings::is_ajax_network_admin),
	 * which is the same mechanism used throughout the codebase. The referer is a
	 * convenience signal, not a security gate — nonces and the capability check in
	 * user_can_sync_settings_for_context() remain the actual security layer.
	 *
	 * {@inheritdoc}
	 */
	public function user_can_make_ajax_call() {
		$capability = Settings::is_ajax_network_admin() ? 'manage_network' : 'manage_options';
		return Helper::is_user_allowed( $capability );
	}

	private function get_restricted_settings_for_current_user() {
		if ( $this->current_user_can_manage_network() ) {
			return array();
		}

		$settings   = Settings::get_instance();
		$by_fields  = array(
			'avif'         => $settings->get_avif_fields(),
			'bulk'         => $settings->get_bulk_fields(),
			'cdn'          => $settings->get_cdn_fields(),
			'integrations' => $settings->get_integrations_fields(),
			'lazy_load'    => $settings->get_lazy_load_fields(),
			'preload'      => $settings->get_preload_fields(),
			'settings'     => $settings->get_settings_fields(),
			'webp'         => $settings->get_webp_fields(),
			'nextgen'      => $settings->get_next_gen_fields(),
		);
		$restricted = array();

		foreach ( $this->all_modules as $module ) {
			if ( ! $this->can_current_user_access_module( $module ) && isset( $by_fields[ $module ] ) ) {
				$restricted = array_merge( $restricted, $by_fields[ $module ] );
			}
		}

		return $restricted;
	}

	public function user_can_sync_settings_for_context( $context ) {
		if ( $this->current_user_can_manage_network() ) {
			return true;
		}

		if ( $this->is_override_disabled() ) {
			return false;
		}

		$network_contexts = array(
			'permissions',
		);

		if ( in_array( $context, $network_contexts, true ) ) {
			return false;
		}

		$contexts_to_module = array(
			'lazyload'  => 'lazy_load',
			'preload'   => 'preload',
			'cdn'       => 'cdn',
			'nextgen'   => 'nextgen',
			'directory' => 'directory_smush',
		);

		if ( isset( $contexts_to_module[ $context ] ) ) {
			return $this->can_current_user_access_module( $contexts_to_module[ $context ] );
		}

		// The only other context is 'site', we will filter out specific settings from it.
		return 'site' === $context;
	}

	public function filter_settings_before_sync( $settings ) {
		$restricted = $this->get_restricted_settings_for_current_user();

		return array_diff_key( $settings, array_flip( $restricted ) );
	}

	public function user_can_see_menu_page( $page ) {
		return Settings::can_access( false, true );
	}

	/**
	 * Gate individual submenu pages.
	 *
	 * {@inheritdoc}
	 */
	public function user_can_see_submenu_page( $page ) {
		// Dashboard is always visible; it is the same entry point as the top-level menu.
		// If there is nothing to see on the dashboard page we redirect to the first accessible submenu.
		if ( self::PAGE_DASHBOARD === $page ) {
			return true;
		}

		return $this->should_render_page( $page );
	}

	protected function get_page_urls() {
		$urls = parent::get_page_urls();

		// Network admin: parent already uses network_admin_url() via Helper::get_page_url().
		if ( is_network_admin() ) {
			return $urls;
		}

		// Subsite admin — gate standalone page URLs by module access.
		$page_module_map = array(
			'lazyLoadUrl'  => 'lazy_load',
			'cdnUrl'       => 'cdn',
			'directoryUrl' => 'directory_smush',
		);

		foreach ( $page_module_map as $url_key => $module ) {
			if ( ! $this->can_current_user_access_module( $module ) ) {
				$urls[ $url_key ] = '#';
			}
		}

		// Settings page root — show only when at least one settings-page module is accessible.
		if ( ! $this->should_render_page( self::PAGE_SETTINGS ) ) {
			$urls['settingsUrl'] = '#';
		}

		// Individual settings tabs that have their own navigation URL.
		if ( ! $this->can_current_user_access_module( 'nextgen' ) ) {
			$urls['nextGenUrl'] = '#';
		}

		if ( ! $this->can_current_user_access_module( 'integrations' ) ) {
			$urls['integrationsUrl'] = '#';
		}

		return $urls;
	}

	protected function get_global_data( $page ) {
		$data = parent::get_global_data( $page );

		$data['viewPermissions'] = $this->get_view_permissions();

		return $data;
	}

	/**
	 * Inject the current subsite-controls value into every localized script so
	 * the Permissions tab can seed itself without an extra request.
	 */
	public function add_permissions_to_localized_data( $data ) {
		$data['permissionsSettings'] = $this->get_permissions_settings_for_react();

		return $data;
	}

	/**
	 * Handle the 'permissions' settings-sync context sent from the React UI.
	 *
	 * Expected payload shape (camelCase keys from React):
	 *   { "mode": "none"|"all"|"custom", "modules": ["bulk","cdn",...] }
	 *
	 * Stored value in wp-smush-networkwide:
	 *   "0"       → none
	 *   "1"       → all
	 *   string[]  → custom (the modules array)
	 *
	 * @param array|null $saved_settings Passed-through from previous filter.
	 * @param array $settings Incoming settings from React.
	 * @param string $context Sync context identifier.
	 *
	 * @return array|null
	 */
	public function handle_permissions_sync( $saved_settings, $settings, $context ) {
		if ( 'permissions' !== $context ) {
			return $saved_settings;
		}

		$mode    = isset( $settings['mode'] ) ? sanitize_text_field( $settings['mode'] ) : 'none';
		$modules = isset( $settings['modules'] ) && is_array( $settings['modules'] )
			? array_values( array_map( 'sanitize_text_field', $settings['modules'] ) )
			: array();

		switch ( $mode ) {
			case 'all':
				$new_value = '1';
				break;
			case 'custom':
				$new_value = $modules;
				break;
			default: // 'none'
				$new_value = '0';
				break;
		}

		update_site_option( Settings::get_subsite_controls_option_id(), $new_value );

		// Return the round-tripped value so React stays in sync with what was persisted.
		return $this->get_permissions_settings_for_react();
	}

	/**
	 * Convert the raw wp-smush-networkwide option to the shape the React UI expects.
	 *
	 * @return array { mode: string, modules: string[] }
	 */
	private function get_permissions_settings_for_react() {
		$value = get_site_option( Settings::get_subsite_controls_option_id() );

		if ( is_array( $value ) ) {
			return array(
				'mode'    => 'custom',
				'modules' => array_values( $value ),
			);
		}

		if ( '1' === $value ) {
			return array(
				'mode'    => 'all',
				'modules' => array(
					'bulk',
					'lazy_load',
					'cdn',
					'directory_smush',
					'integrations',
				),
			);
		}

		// '0', false, empty — default to none.
		return array(
			'mode'    => 'none',
			'modules' => array(),
		);
	}

	private function get_view_permissions() {
		$permissions = array();

		foreach ( $this->all_modules as $module ) {
			$words               = array_map( 'ucfirst', explode( '_', $module ) );
			$key                 = 'canView' . implode( '', $words );
			$permissions[ $key ] = $this->can_current_user_access_module( $module ) ? 1 : 0;
		}

		$permissions['canViewUserProfile'] = is_network_admin();

		return $permissions;
	}

	private function should_render_page( $slug ) {
		// Important: this is highly dependent on the designs and must be kept in sync
		$pages = array(
			self::PAGE_DASHBOARD    => array( 'bulk', 'lazy_load', 'cdn', 'nextgen' ),
			self::PAGE_SETTINGS     => array( 'bulk', 'nextgen', 'integrations', 'settings', 'permissions', 'configs', 'lazy_load' ),
			self::PAGE_LAZY_PRELOAD => array( 'lazy_load', 'preload' ),
			self::PAGE_CDN          => array( 'cdn' ),
			self::PAGE_DIRECTORY    => array( 'directory_smush' ),
		);

		if ( ! isset( $pages[ $slug ] ) ) {
			// If the slug isn't in our map, it's not a real page and shouldn't be rendered.
			return false;
		}

		$page_sections = $pages[ $slug ];
		foreach ( $page_sections as $module ) {
			if ( $this->can_current_user_access_module( $module ) ) {
				return true;
			}
		}

		return false;
	}

	private function is_override_disabled() {
		return ! $this->get_subsite_controls_site_option();
	}

	private function is_full_override_enabled() {
		return $this->get_subsite_controls_site_option() === '1';
	}

	private function is_partial_override_enabled() {
		return is_array( $this->get_subsite_controls_site_option() );
	}

	private function is_module_overridden( $module ) {
		if ( $this->is_full_override_enabled() ) {
			return true;
		}

		if ( $this->is_partial_override_enabled() ) {
			return in_array( $module, $this->get_subsite_controls_site_option() );
		}

		return false;
	}

	private function get_subsite_controls_site_option() {
		if ( is_null( $this->subsite_controls_site_option ) ) {
			$this->subsite_controls_site_option = $this->fetch_subsite_controls_site_option();
		}

		return $this->subsite_controls_site_option;
	}

	private function fetch_subsite_controls_site_option() {
		return get_site_option( Settings::get_subsite_controls_option_id() );
	}

	/**
	 * @return mixed
	 */
	private function current_user_can_manage_network() {
		return current_user_can( 'manage_network' );
	}
}