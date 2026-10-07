<?php
/**
 * Hub_Connector class.
 *
 * @package Smush
 */

namespace Smush\Core;

use Smush\Core\Membership\Membership;
use WPMUDEV\Hub\Connector\API;
use WPMUDEV\Hub\Connector\Data;
use WPMUDEV_Dashboard;
use WPMUDEV\Hub\Connector;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Hub_Connector
 *
 * Handles Hub connection functionality for the Smush plugin.
 */
class Hub_Connector extends Controller {

	/**
	 * The identifier for the Smush plugin in the Hub.
	 *
	 * @const string
	 */
	private static $plugin_identifier = 'smush';

	/**
	 * The action name used for the Hub connection.
	 *
	 * @const string
	 */
	private static $connection_action = 'hub_connection';

	/**
	 * Valid screens for the Hub Connector.
	 *
	 * @var array
	 */
	private static array $valid_screens = array(
		'smush_page_smush-bulk',
		'smush-pro_page_smush-bulk',
		'smush_page_smush-bulk-network',
		'smush-pro_page_smush-bulk-network',
	);

	/**
	 * Array utilities instance.
	 *
	 * @var Array_Utils
	 */
	private $array_utils;

	/**
	 * Hub_Connector constructor.
	 *
	 * Private constructor to enforce singleton pattern.
	 */
	public function __construct() {
		$this->initialize();
		$this->array_utils = new Array_Utils();

		$this->register_action( 'wpmudev_hub_connector_first_sync_completed', array( $this, 'sync_after_connect' ) );
		$this->register_filter( 'wp_smush_modals', array( $this, 'register_hub_connection_success_modal' ) );
		$this->register_filter( 'pre_site_option_wp-smush-networkwide', array( $this, 'disable_subsite_controls_for_unconnected_free_users' ) );
		$this->register_action( 'wp_smush_render_general_setting_rows', array( $this, 'render_hub_connector_actions' ), 30 );
		$this->register_filter( 'wp_smush_localize_script_messages', array( $this, 'add_site_disconnected_success_message' ) );
		$this->register_action( 'wp_ajax_wp_smush_disconnect_site', array( $this, 'ajax_disconnect_site' ) );
		$this->register_action( 'wp_ajax_wp_smush_check_hub_sync_status', array( $this, 'ajax_check_hub_sync_status' ) );
		$this->register_filter( 'wp_smush_localize_ui_script_data', array( $this, 'localized_data_for_ui' ) );

		if ( ! self::is_connection_flow() ) {
			return;
		}
		$this->register_action( 'admin_body_class', array( $this, 'admin_body_class' ), 11 );
		$this->register_action( 'wpmudev_hub_connector_localize_text_vars', array( $this, 'customize_text_vars' ), 10, 2 );
		$this->register_filter( 'wpmudev_hub_connector_localize_vars', array( $this, 'add_hub_connector_data' ), 10, 2 );
	}

	public function localized_data_for_ui( $data ) {
		$dismissed_notices = get_option( 'wp-smush-dismissed-notices', array() );
		$permission_level  = is_multisite() ? 'manage_network' : 'manage_options';

		// TODO: Maybe remove hubConnector if site is already connected.
		$data['hubConnector'] = array(
			'is_available'                 => current_user_can( $permission_level ) && self::is_hub_connector_available(),
			'is_syncing'                   => self::is_syncing(),
			// 'is_team_selection'         => false,
			'has_access'                   => current_user_can( $permission_level ),
			'is_logged_in'                 => Membership::get_instance()->has_access_to_hub(),
			'is_free_account_connected'    => self::is_logged_in() && ! self::is_wpmudev_dashboard_connected(),
			'should_redirect_to_dashboard' => self::should_redirect_to_dashboard(),
			// Not being used for literal output / DB insert.
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			'current_tab'                  => isset( $_GET['hub_connector_callback'] ) ? 'login' : 'register',
			'login_auth_url'               => self::get_hub_site_login_auth_url(),
			'hub_auth_url'                 => self::get_hub_google_login_url(),
			'hub_signup_url'               => self::get_hub_register_url(),
			'redirect_url'                 => self::get_connect_site_url( 'smush' ),
			'forgot_password_url'          => $this->get_hub_forgot_password_url(),
			'domain'                       => self::get_site_domain(),
			'auth_nonce'                   => wp_create_nonce( 'auth_nonce' ),
			'has_login_error'              => self::has_error_in_login(),
			'login_error_message'          => self::get_auth_error(),
			'hide_onboarding'              => isset( $_GET['page_action'] ) && 'hub_connection' === sanitize_text_field( wp_unslash( $_GET['page_action'] ) ) && ! ( boolval( self::has_error_in_login() ) ),
			'info_modal_dismissed'         => ! empty( $dismissed_notices['hub_connect_info_modal'] ),
			'profile_data'                 => $this->get_profile_data_for_ui(),
		);

		return $data;
	}

	/**
	 * Get profile data for UI.
	 *
	 * @return array Profile data.
	 */
	private function get_profile_data_for_ui() {
		$profile_data = $this->get_profile_data();
		// Fallback to WP user data.
		if ( is_wp_error( $profile_data ) ) {
			return null;
		}

		$display_name = $this->array_utils->get_array_value( $profile_data, 'name' );
		$user_name    = $this->array_utils->get_array_value( $profile_data, 'user_name' );
		$avatar       = $this->array_utils->get_array_value( $profile_data, 'avatar' );
		return array(
			'initials'              => $this->get_display_name_initial( $display_name ),
			'avatar'                => $avatar,
			'profileBackgroundColor' => $avatar ? '#f8f8f8' : '#0059ff',
			'profileFontColor'       => '#ffffff',
			'userName'              => $user_name,
			'email'                 => is_email( $user_name ) ? $user_name : '',
			'displayName'           => $display_name,
		);
	}

	/**
	 * Get the initial (first letter) from a display name.
	 *
	 * @param string $display_name The display name.
	 * @return string Uppercase initial or empty string.
	 */
	protected function get_display_name_initial( $display_name ) {
		if ( empty( $display_name ) ) {
			return '';
		}
		return strtoupper( substr( $display_name, 0, 1 ) );
	}

	/**
	 * Initialize the Hub Connector module and set its options.
	 *
	 * @return void
	 */
	private function initialize() {
		$this->load_hub_connector_library();
		$this->configure_hub_connector();
	}

	/**
	 * Add Hub Connector specific classes to admin body.
	 *
	 * @param string $classes Existing CSS classes.
	 * @return string Modified CSS classes.
	 */
	public function admin_body_class( $classes ) {
		if ( ! self::is_valid_screen() || self::is_logged_in() ) {
			return $classes;
		}

		$sui_version = $this->get_sui_version();
		if ( ! empty( $sui_version ) ) {
			$classes .= ' ' . esc_attr( $sui_version );
		}

		return $classes;
	}

	/**
	 * Load the Hub Connector library.
	 *
	 * @return void
	 * @throws \RuntimeException If library file doesn't exist.
	 */
	private function load_hub_connector_library() {
		$hub_connector_lib = WP_SMUSH_DIR . 'core/external/hub-connector/connector.php';

		if ( ! file_exists( $hub_connector_lib ) ) {
			wp_die(
				esc_html__( 'Required library is missing. Please reinstall the plugin.', 'wp-smushit' ),
				esc_html__( 'Library Error', 'wp-smushit' ),
				array(
					'response'  => 500,
					'back_link' => true,
				)
			);
		}

		require_once $hub_connector_lib;
	}

	/**
	 * Configure Hub Connector options.
	 *
	 * @return void
	 */
	private function configure_hub_connector() {
		if ( ! class_exists( '\WPMUDEV\Hub\Connector' ) ) {
			return;
		}

		$options = array(
			'screens' => self::$valid_screens,
		);

		Connector::get()->set_options( self::$plugin_identifier, $options );
	}

	/**
	 * Get SUI version constant.
	 *
	 * @return string
	 */
	private function get_sui_version() {
		return defined( 'WPMUDEV_HUB_CONNECTOR_SUI_VERSION' ) ? WPMUDEV_HUB_CONNECTOR_SUI_VERSION : '';
	}

	/**
	 * Check if current screen is valid for Hub Connector.
	 *
	 * @return bool
	 */
	private static function is_valid_screen() {
		$current_screen = get_current_screen();

		if ( ! $current_screen || ! isset( $current_screen->id ) ) {
			return false;
		}

		return in_array( $current_screen->id, self::$valid_screens, true );
	}

	/**
	 * Render the Hub Connector page.
	 *
	 * @return void
	 */
	public static function render() {
		do_action( 'wpmudev_hub_connector_ui', self::$plugin_identifier );
	}

	/**
	 * Checks if the current request is a Hub Connection flow.
	 *
	 * @return bool
	 */
	public static function is_connection_flow() {
		$action = self::get_sanitized_input( 'page_action' );

		return ! empty( $action ) && self::$connection_action === $action;
	}

	/**
	 * Checks if Hub Connector grants access to the page.
	 *
	 * @return bool
	 */
	public static function has_access() {
		return self::is_hub_connector_available() && self::is_logged_in();
	}

	/**
	 * Checks if Hub Connector is available.
	 *
	 * @return bool
	 */
	private static function is_hub_connector_available() {
		return class_exists( '\WPMUDEV\Hub\Connector' );
	}

	/**
	 * Checks if Hub Connector is logged in.
	 *
	 * @return bool
	 */
	public static function is_logged_in() {
		if ( ! class_exists( '\WPMUDEV\Hub\Connector\API' ) ) {
			return false;
		}

		$api = API::get();

		return $api && method_exists( $api, 'is_logged_in' ) && $api->is_logged_in();
	}

	/**
	 * Sync site data with Hub.
	 *
	 * @return bool|WP_Error
	 */
	private function sync() {
		if ( ! class_exists( '\WPMUDEV\Hub\Connector\API' ) ) {
			return false;
		}

		$api = API::get();

		if ( $api && method_exists( $api, 'sync_site' ) ) {
			$sync = $api->sync_site();
			if ( is_wp_error( $sync ) ) {
				return $sync;
			}
		}

		return true;
	}

	/**
	 * Disconnect site from Hub.
	 *
	 * @return bool
	 */
	public static function disconnect() {
		if ( ! class_exists( '\WPMUDEV\Hub\Connector\API' ) ) {
			return false;
		}

		$api = API::get();

		return $api && method_exists( $api, 'logout' ) && $api->logout();
	}

	/**
	 * Get connection URL for Hub.
	 *
	 * @param string $target_page   The target page to connect to.
	 * @param string $utm_campaign  The UTM campaign to append to the URL.
	 *
	 * @return string The connection URL.
	 */
	public static function get_connect_site_url( $target_page = 'smush', $utm_campaign = '' ) {
		$args = array();

		if ( self::should_redirect_to_dashboard() ) {
			$args['page'] = 'wpmudev';
		} else {
			$args = self::get_connection_args( $target_page );
		}

		if ( ! empty( $utm_campaign ) ) {
			$args['utm_campaign'] = sanitize_text_field( $utm_campaign );
		}

		$admin_url = self::get_admin_url();

		return add_query_arg( $args, $admin_url );
	}

	/**
	 * Check if should redirect to WPMUDEV Dashboard.
	 *
	 * @return bool
	 */
	private static function should_redirect_to_dashboard() {
		return ! self::is_wpmudev_dashboard_connected() && class_exists( 'WPMUDEV_Dashboard' );
	}

	/**
	 * Get connection arguments for URL.
	 *
	 * @param string $target_page The target page.
	 * @return array
	 */
	private static function get_connection_args( $target_page ) {
		return array(
			'page'                   => sanitize_text_field( $target_page ),
			'_wpnonce'               => wp_create_nonce( self::$connection_action ),
			'page_action'            => self::$connection_action,
			'hub_connector_callback' => 1,
		);
	}

	/**
	 * Get appropriate admin URL.
	 *
	 * @return string
	 */
	private static function get_admin_url() {
		return is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
	}

	/**
	 * Check if WPMUDEV Dashboard is connected.
	 *
	 * @return bool
	 */
	public static function is_wpmudev_dashboard_connected() {
		if ( ! class_exists( 'WPMUDEV_Dashboard' ) ) {
			return false;
		}

		$dashboard_api = WPMUDEV_Dashboard::$api ?? null;

		return is_object( $dashboard_api ) &&
				method_exists( $dashboard_api, 'has_key' ) &&
				$dashboard_api->has_key();
	}

	/**
	 * Checks if the Hub connector should render its UI.
	 *
	 * Verifies the nonce and login status to determine if the Hub connector should render its UI.
	 *
	 * @return bool True if should render, false otherwise.
	 */
	public static function should_render() {
		if ( self::is_logged_in() || ! self::is_valid_screen() ) {
			return false;
		}

		return self::verify_connection_nonce();
	}

	/**
	 * Verify the connection nonce.
	 *
	 * @return bool
	 */
	private static function verify_connection_nonce() {
		$nonce = self::get_sanitized_input( '_wpnonce' );

		if ( empty( $nonce ) ) {
			return false;
		}

		return wp_verify_nonce( $nonce, self::$connection_action ) !== false;
	}

	/**
	 * Get sanitized input from GET parameters.
	 *
	 * @param string $key The input key to retrieve.
	 * @param mixed  $default_value Default value if key doesn't exist.
	 * @return mixed Sanitized input value or default.
	 */
	private static function get_sanitized_input( $key, $default_value = '' ) {
		$value = filter_input( INPUT_GET, $key, FILTER_UNSAFE_RAW );

		if ( null === $value ) {
			return $default_value;
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Modify text string vars.
	 *
	 * @param array  $texts  Vars.
	 * @param string $plugin_id Plugin identifier.
	 *
	 * @return array
	 */
	public function customize_text_vars( $texts, $plugin_id ) {
		if ( self::$plugin_identifier === $plugin_id ) {
			$feature      = $this->get_feature_name();
			$feature_part = ucfirst( self::$plugin_identifier ) . ' - ' . esc_html( $feature );

			$texts['create_account_desc'] = sprintf(
				/* translators: %1$s: Feature, %2$s: Opening italic tag, %3$s: Closing italic tag. */
				esc_html__( 'Create a free account to connect your site to WPMU DEV and activate %1$s. %2$s It`s fast, seamless, and free. %3$s', 'wp-smushit' ),
				'<strong>' . $feature_part . '</strong>',
				'<i>',
				'</i>'
			);
			$texts['login_desc'] = sprintf(
				/* translators: %s: Feature */
				esc_html__( 'Log in with your WPMU DEV account credentials to activate %s.', 'wp-smushit' ),
				$feature_part
			);
		}

		return $texts;
	}

	/**
	 * Get the feature name for the current screen.
	 *
	 * @return string
	 */
	private function get_feature_name() {
		$feature_name = __( 'Bulk Smush', 'wp-smushit' );

		$request_uri = ( new Server_Utils() )->get_request_uri();
		if ( str_contains( $request_uri, 'smush_settings_permissions_subsite_controls' ) ) {
			$feature_name = __( 'Subsite Controls', 'wp-smushit' );
		}

		return $feature_name;
	}

	/**
	 * Adds the Hub connector data to the Smush data.
	 *
	 * @param array  $extra_args The Smush data.
	 * @param string $plugin_id Plugin identifier.
	 *
	 * @return array The Smush data with the Hub connector data.
	 */
	public function add_hub_connector_data( $extra_args, $plugin_id ) {
		if ( self::$plugin_identifier === $plugin_id ) {
			$register_url = $this->array_utils->get_array_value( $extra_args, array( 'login', 'register_url' ) );
			if ( $register_url && is_string( $register_url ) ) {
				$extra_args['login']['register_url'] = $this->get_register_url_with_utm( $register_url );
			}

			if ( is_multisite() ) {
				$this->remove_filter( 'pre_site_option_wp-smush-networkwide' );

				$activated_subsite_modules = Settings::get_instance()->get_activated_subsite_modules_list();
				$network_can_access_bulk   = ! in_array( 'bulk', $activated_subsite_modules, true );
				$current_url               = $this->array_utils->get_array_value( $extra_args, array( 'login', 'current_url' ) );

				if ( $current_url && ! $network_can_access_bulk ) {
					$dashboard_url = Helper::get_page_url( 'smush' );
					// Update the redirect URL after the site is connected successfully.
					$extra_args['login']['current_url'] = $dashboard_url;
				}

				$this->restore_filter( 'pre_site_option_wp-smush-networkwide' );
			}
		}

		return $extra_args;
	}

	/**
	 * Get register URL with UTM parameters.
	 *
	 * @param string $register_url The base register URL.
	 * @return string The register URL with UTM parameters.
	 */
	private function get_register_url_with_utm( $register_url ) {
		$utm_campaign = filter_input( INPUT_GET, 'utm_campaign', FILTER_UNSAFE_RAW );
		return add_query_arg(
			array(
				'utm_medium'   => 'plugin',
				'utm_source'   => self::$plugin_identifier,
				'utm_campaign' => empty( $utm_campaign ) ? 'smush_bulk_smush_connect' : esc_attr( $utm_campaign ),
				'utm_content'  => 'hub-connector',
			),
			$register_url
		);
	}

	/**
	 * Sync data after successful connection.
	 *
	 * @return void
	 */
	public function sync_after_connect() {
		add_site_option( 'wp_smush_show_connected_modal', true );
		delete_site_transient( 'wp_smush_hc_site_syncing' );
	}

	/**
	 * Register the hub connection success modal.
	 *
	 * @param array $modals Registered modals.
	 * @return array
	 */
	public function register_hub_connection_success_modal( $modals ) {
		if ( get_site_option( 'wp_smush_show_connected_modal' ) ) {
			delete_site_option( 'wp_smush_show_connected_modal' );
			$modals['hub-connection-success'] = array();
		}

		if ( self::is_logged_in() ) {
			$modals['disconnect-site'] = array();
		}

		return $modals;
	}

	/**
	 * Disable Subsite Controls for Unconnected Free Users.
	 *
	 * @param mixed $pre_value Pre option value.
	 * @return mixed
	 */
	public function disable_subsite_controls_for_unconnected_free_users( $pre_value ) {
		if ( Membership::get_instance()->is_api_hub_access_required() ) {
			// 0: None, 1: All, Array list modules: Custom.
			return 0;
		}

		return $pre_value;
	}

	/**
	 * Renders the Hub Connector actions.
	 */
	public function render_hub_connector_actions() {
        $is_site_connected          = self::is_logged_in();
		$is_required_api_hub_access = Membership::get_instance()->is_api_hub_access_required();
		if ( ! $is_site_connected && ! $is_required_api_hub_access ) {
			return;
		}
		?>
		<div class="sui-box-settings-row" id="general-hub-connector-row">
			<div class="sui-box-settings-col-1">
				<span class="sui-settings-label "><?php esc_html_e( 'Hub Connector', 'wp-smushit' ); ?></span>
				<span class="sui-description">
					<?php esc_html_e( "Connects your site to the WPMU DEV Free Plan, unlocking the plugin's Free plan features.", 'wp-smushit' ); ?>
				</span>
			</div>
			<div class="sui-box-settings-col-2">
				<?php if ( $is_site_connected ) : ?>
					<button type="button" class="sui-button sui-button-ghost" data-esc-close="false" data-modal-open="smush-disconnect-site-modal" data-modal-open-focus="dialog-close-div" data-modal-mask="true">
						<span class="sui-button-text-default">
							<span class="sui-icon-plug-disconnected" aria-hidden="true"></span>
							<?php esc_html_e( 'Disconnect site', 'wp-smushit' ); ?>
						</span>
					</button>
				<?php else : ?>
					<a href="<?php echo esc_url( self::get_connect_site_url( 'smush-bulk', 'smush_settings_general_connect' ) ); ?>" class="sui-button sui-button-blue smush-button-dark-blue">
						<span class="sui-icon-plug-connected" aria-hidden="true"></span>
						<?php esc_html_e( 'Connect site', 'wp-smushit' ); ?>
					</a>
				<?php endif; ?>
				<span class="sui-description"><?php esc_html_e( 'Note: disconnecting your site from WPMU DEV will disable other services that rely on this connection.', 'wp-smushit' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler to check Hub sync status.
	 *
	 * @return void
	 */
	public function ajax_check_hub_sync_status() {
		check_ajax_referer( 'auth_nonce', 'nonce' );

		$capability = is_multisite() ? 'manage_network' : 'manage_options';
		if ( ! Helper::is_user_allowed( $capability ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'wp-smushit' ) ), 403 );
		}

		$sync = $this->sync();
		if ( is_wp_error( $sync ) && 'not_logged_in' !== $sync->get_error_code() ) {
			delete_site_transient( 'wp_smush_hc_site_syncing' );

			wp_send_json_error(
				array(
					'is_synced'     => false,
					'error_code'    => $sync->get_error_code(),
					'error_message' => $sync->get_error_message(),
				),
				403
			);
		}

		$is_logged_in = self::is_logged_in();
		delete_site_transient( 'wp_smush_hc_site_syncing' );

		wp_send_json_success(
			array(
				'is_synced' => $is_logged_in,
			)
		);
	}

	/**
	 * Disconnect the site from the hub.
	 *
	 * @return void
	 */
	public function ajax_disconnect_site() {
		check_ajax_referer( 'wp-smush-ajax' );

		$capability = is_multisite() ? 'manage_network' : 'manage_options';
		// Check capability.
		if ( ! Helper::is_user_allowed( $capability ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-smushit' ), 403 );
		}

		$this->disconnect();

		// No Need to send json response for other requests.
		wp_send_json_success();
	}

	/**
	 * Add site disconnected success message.
	 *
	 * @param mixed $messages Smush data messages.
	 * @return array
	 */
	public function add_site_disconnected_success_message( $messages ) {
		$messages['site_disconnected_success'] = __( 'Site disconnected successfully.', 'wp-smushit' );

		return $messages;
	}

	/**
	 * Get the Hub forgot password URL.
	 *
	 * @return string Forgot password URL.
	 */
	private function get_hub_forgot_password_url() {
		if ( ! class_exists( '\WPMUDEV\Hub\Connector\Data' ) ) {
			return '';
		}

		$current_page = 'smush';
		$utm_campaign = 'smush_forgot_password';

		return self::generate_hub_url(
			\WPMUDEV\Hub\Connector\Data::get()->server_url( 'forgot-password' ),
			array(),
			$current_page,
			$utm_campaign
		);
	}

	/**
	 * Get Hub register URL with site connection parameters.
	 *
	 * @return string
	 */
	public static function get_hub_register_url( $current_page = 'smush', $utm_campaign = 'smush_bulk_smush_connect' ) {
		// Check if hub connector Data class is available.
		if ( ! class_exists( '\WPMUDEV\Hub\Connector\Data' ) ) {
			return '';
		}

		return self::generate_hub_url(
			\WPMUDEV\Hub\Connector\Data::get()->server_url( 'register' ),
			array(
				'signup' => 'site-connect',
			),
			$current_page,
			$utm_campaign
		);
	}

	/**
	 * Generate the Hub URL.
	 *
	 * @param mixed $hub_base_url
	 * @param array $query_params
	 * @param string $current_page
	 * @param string $utm_campaign
	 *
	 * @return string
	 */
	private static function generate_hub_url( $hub_base_url, $query_params = array(), $current_page = 'smush', $utm_campaign = 'smush_bulk_smush_connect' ) {
		// Get the hub connection URL (includes page, nonce, page_action, utm_campaign).
		$hub_connect_url = self::get_connect_site_url( $current_page, $utm_campaign );

		// Prepare redirect URL with callback and auth nonce.
		$auth_nonce   = wp_create_nonce( 'auth_nonce' );
		$redirect_url = add_query_arg(
			array(
				'hub_connector_callback' => 1,
				'auth_nonce'             => $auth_nonce,
			),
			$hub_connect_url
		);

		$query_params = wp_parse_args(
			$query_params,
			array(
				'site_connect_url' => rawurlencode( $redirect_url ),
				'utm_medium'       => 'plugin',
				'utm_source'       => 'smush',
				'utm_campaign'     => $utm_campaign,
				'utm_content'      => 'hub-connector',
			)
		);

		return add_query_arg(
			$query_params,
			$hub_base_url
		);
	}

	/**
	 * Get Hub Google login URL (https://wpmudev.com/api/dashboard/v2/google-auth).
	 *
	 * @return string
	 */
	public static function get_hub_google_login_url() {
		return \WPMUDEV\Hub\Connector\Data::get()->server_url( 'api/dashboard/v2/google-auth' );
	}

	/**
	 * Get Hub Login Auth URL (https://wpmudev.com/api/dashboard/v2/site-authenticate).
	 *
	 * @return string
	 */
	public static function get_hub_site_login_auth_url() {
		return \WPMUDEV\Hub\Connector\API::get()->rest_url( 'site-authenticate' );
	}

	/**
	 * Get the site domain.
	 *
	 * @return string
	 */
	public static function get_site_domain() {
		return \WPMUDEV\Hub\Connector\Data::get()->network_site_url();
	}

	/**
	 * Verify the auth nonce from request.
	 *
	 * @return bool
	 */
	public static function verify_nonce() {
		return wp_verify_nonce( ( sanitize_text_field( wp_unslash( $_REQUEST['auth_nonce'] ?? '' ) ) ), 'auth_nonce' );
	}

	/**
	 * Check if the site is syncing.
	 *
	 * @return bool
	 */
	public static function is_syncing() {
		$syncing = current_user_can( 'manage_options' ) && self::verify_nonce() && ! empty( $_REQUEST['page_action'] ) && 'hub_connection' === $_REQUEST['page_action'] && ! empty( $_REQUEST['set_apikey'] );
		if ( $syncing ) {
			// HC removes params and in short window logged_in won't return the state.
			set_site_transient( 'wp_smush_hc_site_syncing', true );
		}

		return get_site_transient( 'wp_smush_hc_site_syncing' ) ? true : false;
	}

	/**
	 * Check if there is an error in login response from HUB.
	 *
	 * @return bool
	 */
	public static function has_error_in_login() {
		$page_action    = sanitize_text_field( wp_unslash( $_GET['page_action'] ?? '' ) );
		$api_error      = sanitize_text_field( wp_unslash( $_GET['api_error'] ?? 0 ) );
		$is_hub_callback = ! empty( $_GET['hub_connector_callback'] );

		if ( 'hub_connection' === $page_action ) {
			return ! empty( $api_error );
		}

		if ( ! $is_hub_callback ) {
			return false;
		}

		if ( ! self::verify_nonce() ) {
			return false;
		}

		return ! empty( $api_error );
	}

	/**
	 * Get authentication error messages. Copied from HC.
	 *
	 * Based on the error code, prepare different error messages.
	 *
	 * @return string
	 */
	public static function get_auth_error() {
		/**
		 * Nonce is verified in `process_auth_callback` before this method called.
		 *
		 * @see self::process_auth_callback()
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Recommended

		$error               = '';
		$reset_url           = \WPMUDEV\Hub\Connector\Data::get()->server_url( 'forgot-password' );
		$skip_trial_url      = \WPMUDEV\Hub\Connector\Data::get()->server_url( 'hub/account/?skip_trial' );
		$trial_info_url      = \WPMUDEV\Hub\Connector\Data::get()->server_url( 'docs/getting-started/how-free-trials-work/' );
		$websites_url        = \WPMUDEV\Hub\Connector\Data::get()->server_url( 'hub2/' );
		$security_info_url   = \WPMUDEV\Hub\Connector\Data::get()->server_url( 'manuals/hub-security/' );
		$support_url         = \WPMUDEV\Hub\Connector\Data::get()->server_url( 'hub/support/' );
		$account_details_url = \WPMUDEV\Hub\Connector\Data::get()->server_url( 'hub2/account/details/' );

		if ( isset( $_GET['api_error'] ) ) {
			// Get errors.
			$api_error  = sanitize_key( wp_unslash( $_GET['api_error'] ) );
			$auth_error = sanitize_key( wp_unslash( $_GET['auth_error'] ?? '' ) );

			if ( 1 === (int) $api_error || 'auth' === $api_error ) {
				switch ( $auth_error ) {
					case 'google_linked':
						$error = sprintf(
						// translators: %s Account detail URL.
							__(
								'You are currently using your Google account as your preferred login method. If you wish to login with your WPMU DEV email & password instead, please change the <strong>Login Method</strong> in <a href="%s" target="_blank">your WPMU DEV account</a>.',
								'wp-smushit'
							),
							$account_details_url
						);
						break;
					case 'google_unlinked':
						$error = sprintf(
						// translators: %s Account detail URL.
							__(
								'You are currently using your WPMU DEV email & password as your preferred login method. If you wish to login with your Google account instead, please change the <strong>Login Method</strong> in <a href="%s" target="_blank">your WPMU DEV account</a>.',
								'wp-smushit'
							),
							$account_details_url
						);
						break;
					case 'reauth_google':
						$error = sprintf(
						// translators: %1$s Account detail URL, %2$s Reset URL.
							__(
								'Due to security improvements, you will need to re-link your Google account in The Hub. Please log in with your WPMU DEV email & password for now, then set up your preferred <strong>Login Method</strong> in <a href="%1$s" target="_blank">your WPMU DEV account</a>. Forgot your password? You can <a href="%2$s" target="_blank"><strong>reset it here</strong></a>.',
								'wp-smushit'
							),
							$account_details_url,
							$reset_url
						);
						break;
					default:
						// Invalid credentials.
						$error = sprintf(
							'%s<br><a href="%s" target="_blank"><strong>%s</strong></a>',
							esc_html__( 'Your login details were incorrect. Please make sure you\'re using your WPMU DEV email and password and try again.', 'wp-smushit' ),
							$reset_url,
							esc_html__( 'Forgot your password?', 'wp-smushit' )
						);
						break;
				}
			} else {
				switch ( $api_error ) {
					case 'in_trial':
						$error = sprintf(
							'%s<br><a href="%s" target="_blank">%s</a>',
							sprintf(
							// translators: %1$s Rest URL, %2$s Upgrade URL, %3$s Trial URL.
								__(
									'This domain has previously been registered with us by the user %1$s. To use WPMU DEV on this domain, you can either log in with the original account (you can <a target="_blank" href="%2$s"><strong>reset your password</strong></a>) or <a target="_blank" href="%3$s">upgrade your trial</a> to a full membership. Trial accounts can\'t use previously registered domains - <a target="_blank" href="%4$s">here\'s why</a>.',
									'wp-smushit'
								),
								'<strong style="word-break: break-all;">' . esc_html( $_GET['display_name'] ) . '</strong>', // phpcs:ignore
								$reset_url,
								$skip_trial_url,
								$trial_info_url
							),
							$support_url,
							__( 'Contact support if you need further assistance &raquo;', 'wp-smushit' )
						);
						break;
					case 'already_registered':
						$error = sprintf(
						// translators: %1$d Account name, %2$s Security info, %3$s Hub URL, %4$s Support URL.
							__(
								'This site is currently registered to %1$s. For <a target="_blank" href="%2$s">security reasons</a> they will need to go to the <a target="_blank" href="%3$s">WPMU DEV Hub</a> and remove this domain before you can log in. If you do not have access to that account, and have no way of contacting that user, please <a target="_blank" href="%4$s">contact support for assistance</a>.',
								'wp-smushit'
							),
							! isset( $_GET['display_name'] ) ? __( 'a different user', 'wp-smushit' ) :
							'<strong style="word-break: break-all;">' . esc_html( $_GET['display_name'] ) . '</strong>', // phpcs:ignore.
							$security_info_url,
							$websites_url,
							$support_url
						);
						break;
					case 'banned_account':
						$error = sprintf(
						// translators: %s Support URL.
							__( 'This domain cannot be registered to your WPMU DEV account.<br><a href="%s">Contact Accounts & Billing if you need further assistance »</a>', 'wp-smushit' ),
							\WPMUDEV\Hub\Connector\Data::get()->server_url( 'hub2/#ask-question' )
						);
						break;
					case 'expired_membership':
						$error = sprintf(
						// translators: %1$s Hub Account URL, %2$s: Switch to Free URL.
							__(
								'Login failed — your WPMU DEV membership has expired. Renew now to regain full access, or switch to our free plan to continue managing all your site in The Hub.<br/><br/><a class="sui-button sui-button-blue" href="%1$s" target="_blank">Renew Membership</a>&nbsp;<a class="sui-button sui-button-ghost" href="%2$s" target="_blank">Switch to Free</a>',
								'wp-smushit'
							),
							\WPMUDEV\Hub\Connector\Data::get()->server_url( 'hub2/account/ ' ),
							\WPMUDEV\Hub\Connector\Data::get()->server_url( 'hub2/?switch-free=1 ' )
						);
						break;
					case 'invalid_nonce':
					case 'invalid_double_submit_cookie':
					case 'invalid_google_creds':
					case '':
						$error = __( 'Google login failed. Please try again.', 'wp-smushit' );
						break;
					default:
						// This in case we add new error types in the future.
						$error = __( 'Unknown error. Please update the WPMU DEV Dashboard plugin and try again.', 'wp-smushit' );
						break;
				}
			}
		} elseif ( ! empty( $_REQUEST['connection_error'] ) ) {
			// Variable `$connection_error` is set by the UI function `render_dashboard`.
			$error = sprintf(
				'%s<br>%s<br><em>%s</em>',
				__( 'Your server had a problem connecting to WPMU DEV. Please try again.', 'wp-smushit' ),
				__( 'If this problem continues, please contact your host with this error message and ask:', 'wp-smushit' ),
				sprintf(
				// translators: url to API.
					__( '"Is PHP on my server properly configured to be able to contact %s with a POST HTTP request via fsockopen or CURL?"', 'wp-smushit' ),
					\WPMUDEV\Hub\Connector\Data::get()->server_url()
				)
			);
		} elseif ( ! empty( $_REQUEST['invalid_key'] ) ) {
			// Invalid API key.
			$error = __( 'Your API Key was invalid. Please try again.', 'wp-smushit' );
		}

		/**
		 * Filter to modify auth error text.
		 *
		 * @since 1.0.0
		 *
		 * @param string $error  Error message.
		 * @param string $plugin Plugin identifier.
		 */
		return apply_filters( 'wpmudev_hub_connector_get_auth_error', $error, self::$plugin_identifier );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Get profile data.
	 *
	 * @return WP_Error|array
	 */
	private function get_profile_data() {
		// Hub Connector connected — use its profile data.
		if ( self::is_logged_in() && class_exists( '\WPMUDEV\Hub\Connector\Data' ) ) {
			$membership_data = Data::get()->profile_data( true );
			if ( is_array( $membership_data ) && ! empty( $membership_data['user_name'] ) ) {
				return $membership_data;
			}
		}

		// Dashboard connected — use Dashboard profile.
		if ( self::is_wpmudev_dashboard_connected() && method_exists( WPMUDEV_Dashboard::$api, 'get_profile' ) ) {
			$profile = WPMUDEV_Dashboard::$api->get_profile();
			if ( ! empty( $profile['profile'] ) && is_array( $profile['profile'] ) ) {
				return $profile['profile'];
			}
		}

		return new WP_Error(
			'not_logged_in',
			__( 'Authentication required. Please log in.', 'wp-smushit' )
		);
	}
}