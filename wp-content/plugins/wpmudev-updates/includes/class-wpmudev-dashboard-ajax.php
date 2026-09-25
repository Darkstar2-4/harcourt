<?php
/**
 * Class that handles ajax requests.
 *
 * @link    https://wpmudev.com
 * @since   4.11.6
 * @author  Joel James <joel@incsub.com>
 * @package WPMUDEV_Dashboard_Ajax
 */

// If this file is called directly, abort.
defined( 'WPINC' ) || die;

/**
 * Class WPMUDEV_Dashboard_Ajax
 */
class WPMUDEV_Dashboard_Ajax {

	/**
	 * Available action names.
	 *
	 * @var string[] $actions
	 */
	private array $actions = array();

	/**
	 * Available nopriv action names.
	 *
	 * @var string[] $nopriv_actions
	 */
	private array $nopriv_actions
		= array(
			'wdpunauth',
			'wdpsso_step1',
			'wdpsso_step2',
		);

	/**
	 * Available action names which can be bypassed.
	 *
	 * @var string[] $bypass_actions
	 */
	private array $bypass_actions = array();

	/**
	 * WPMUDEV_Dashboard_Ajax constructor.
	 *
	 * @since 4.11.6
	 */
	public function __construct() {
		// Register all ajax requests.
		foreach ( $this->get_actions() as $action ) {
			add_action( "wp_ajax_$action", array( $this, 'process' ) );
		}

		// Register nopriv ajax actions.
		foreach ( $this->get_nopriv_actions() as $action ) {
			add_action( "wp_ajax_$action", array( $this, 'nopriv_process' ) );
			add_action( "wp_ajax_nopriv_$action", array( $this, 'nopriv_process' ) );
		}

		// AUTO login ajax (nonce protected, it's called by our auto install server).
		add_action( 'wp_ajax_wdp-dashboard-autologin', array( $this, 'dashboard_autologin' ) );
	}

	/**
	 * Get available actions.
	 *
	 * @since 5.0.0
	 *
	 * @return array
	 */
	private function get_actions(): array {
		/**
		 * Override available ajax actions.
		 *
		 * @since 5.0.0
		 *
		 * @param array $actions
		 */
		return apply_filters( 'wpmudev_dashboard_ajax_actions', $this->actions );
	}

	/**
	 * Get available nopriv actions.
	 *
	 * @since 5.0.0
	 *
	 * @return array
	 */
	private function get_nopriv_actions(): array {
		/**
		 * Override available ajax nopriv actions.
		 *
		 * @since 5.0.0
		 *
		 * @param array $nopriv_actions
		 */
		return apply_filters( 'wpmudev_dashboard_ajax_nopriv_actions', $this->nopriv_actions );
	}

	/**
	 * Entry point for all ajax requests of the plugin.
	 *
	 * All ajax handlers point to this function instead of an individual
	 * callback function; this function validates the user before processing the
	 * actual request.
	 *
	 * @since  4.0.0
	 * @since  4.11.6
	 * @internal
	 */
	public function process() {
		// Make sure required items are found.
		if ( empty( $_REQUEST['action'] ) || empty( $_REQUEST['hash'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Required field missing', 'wpmudev' ) )
			);
		}

		// Get action name.
		$action = str_replace( 'wdp-', '', $_REQUEST['action'] ); // phpcs:ignore
		// Get nonce.
		$nonce = $_REQUEST['hash']; // phpcs:ignore

		// Do nothing if the nonce is invalid.
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Something went wrong, please refresh the page and try again.', 'wpmudev' ) )
			);
		}

		// Do nothing if the user is not allowed to use the Dashboard. Exception for specific ajax actions.
		if ( ! in_array( $action, $this->bypass_actions, true ) && ! WPMUDEV_Dashboard::$site->allowed_user() ) {
			wp_send_json_error(
				array( 'message' => __( 'Sorry, you are not allowed to do this.', 'wpmudev' ) )
			);
		}

		// Method names should contain only underscores.
		$method = str_replace( '-', '_', $action );

		if ( method_exists( $this, $method ) ) {
			// Execute request action.
			call_user_func( array( $this, $method ) );
		} else {
			$this->send_json_error(
				array(
					'message' => sprintf(
					/* translators: %s action name. */
						__( 'Unknown action: %s', 'wpmudev' ),
						esc_html( $action )
					),
				)
			);
		}

		// When the method did not send a response assume error.
		wp_send_json_error(
			array( 'message' => __( 'Unexpected action, we could not handle it.', 'wpmudev' ) )
		);
	}

	/**
	 * Entry point for all public ajax requests of the plugin.
	 *
	 * All Ajax handlers point to this function instead of an individual
	 * callback function; These functions are available even when logged out.
	 *
	 * @since  4.0.0
	 * @internal
	 */
	public function nopriv_process() {
		// Do nothing if function was called incorrectly.
		if ( empty( $_REQUEST['action'] ) ) { // phpcs:ignore
			wp_send_json_error(
				array( 'message' => __( 'Required field missing', 'wpmudev' ) )
			);
		}

		// Get action name.
		$method = str_replace( '-', '_', $_REQUEST['action'] ); // phpcs:ignore

		if ( method_exists( $this, $method ) ) {
			// Execute request action.
			call_user_func( array( $this, $method ) );
		} else {
			$this->send_json_error(
				array(
					'message' => sprintf(
					/* translators: %s action name. */
						__( 'Unknown action: %s', 'wpmudev' ),
						esc_html( $_REQUEST['action'] ) // phpcs:ignore
					),
				)
			);
		}

		// When the method did not send a response assume error.
		wp_send_json_error();
	}

	/**
	 * Start authentication.
	 *
	 * Required POST params:
	 * - wdpunkey .. Temporary Auth Key from the DB.
	 * - staff    .. Name of the user who loggs in.
	 *
	 * @since 4.11.6
	 *
	 * @return void
	 */
	public function wdpunauth() {
		WPMUDEV_Dashboard::$api->authenticate_remote_access();
	}

	/**
	 * Process authentication step 1.
	 *
	 * @since 4.11.6
	 *
	 * @return void
	 */
	public function wdpsso_step1() {
		// nonce verify method is using state + hmac.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$redirect = rawurlencode( wp_unslash( $_REQUEST['redirect'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- this is encoded value.
		$nonce    = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ?? '' ) );
		$jwttoken = sanitize_text_field( wp_unslash( $_REQUEST['jwttoken'] ?? '' ) );
		$apikey   = sanitize_text_field( wp_unslash( $_REQUEST['apikey'] ?? '' ) );
		$hubteam  = (int) sanitize_text_field( wp_unslash( $_REQUEST['hubteam'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		WPMUDEV_Dashboard::$api->authenticate_sso_access_step1( $redirect, $nonce, $jwttoken, $apikey, $hubteam );
	}

	/**
	 * Process authentication step 2.
	 *
	 * @since 4.11.6
	 *
	 * @return void
	 */
	public function wdpsso_step2() {
		// nonce verify method is using state + hmac.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$data = array(
			'incoming_hmac'  => sanitize_text_field( wp_unslash( $_REQUEST['outgoing_hmac'] ?? '' ) ),
			'token'          => sanitize_text_field( wp_unslash( $_REQUEST['token'] ?? '' ) ),
			'pre_sso_state'  => sanitize_text_field( wp_unslash( $_REQUEST['pre_sso_state'] ?? '' ) ),
			'redirect'       => wp_unslash( $_REQUEST['redirect'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- this is encoded value.
			'dev_user_id'    => (int) sanitize_text_field( ( wp_unslash( $_REQUEST['dev_user_id'] ?? '' ) ) ),
			'dev_user_email' => sanitize_email( wp_unslash( $_REQUEST['dev_user_email'] ?? '' ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		WPMUDEV_Dashboard::$api->authenticate_sso_access_step2( $data );
	}

	/**
	 * Autologin to dashboard plugin.
	 * - Hub sync
	 * - Auto upgrade free plugins to pro
	 *
	 * @since 4.11.6
	 */
	public function dashboard_autologin() {
		// nonce verifier.
		$auth_verify_nonce = wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['auth_nonce'] ?? '' ) ), 'auth_nonce' );
		if ( ! $auth_verify_nonce ) {
			$this->send_json_error(
				array(
					'type'    => 'invalid_auth',
					'message' => __( 'Invalid Authentication.', 'wpmudev' ),
				)
			);
		}

		// basic permissions ( even allowed_user have to have this cap anyway ).
		if ( ! current_user_can( ( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) ) {
			$this->send_json_error(
				array(
					'type'    => 'invalid_permission',
					'message' => __( 'Invalid Permission.', 'wpmudev' ),
				)
			);
		}

		// Dash Allowed users only.
		if ( ! WPMUDEV_Dashboard::$site->allowed_user() ) {
			$this->send_json_error(
				array(
					'type'    => 'invalid_allow',
					'message' => __( 'Invalid Permission.', 'wpmudev' ),
				)
			);
		}

		$key               = isset( $_REQUEST['apikey'] ) ? trim( sanitize_text_field( wp_unslash( $_REQUEST['apikey'] ) ) ) : false;
		$skip_free_upgrade = isset( $_REQUEST['skip_upgrade_free_plugins'] );

		if ( ! $key ) {
			$this->send_json_error(
				array(
					'type'    => 'invalid_key',
					'message' => __( 'Your API Key was invalid.', 'wpmudev' ),
				)
			);
		}

		$previous_key = '';
		if ( WPMUDEV_Dashboard::$api->has_key() ) {
			$previous_key = WPMUDEV_Dashboard::$api->get_key();
		}

		WPMUDEV_Dashboard::$api->set_key( $key );

		// When we auto install, we will also have the hub_sso_status param available to enable/disable SSO.
		if ( isset( $_REQUEST['hub_sso_status'] ) ) {
			WPMUDEV_Dashboard::$settings->set( 'enabled', absint( $_REQUEST['hub_sso_status'] ), 'sso' );
			if ( 1 === absint( $_REQUEST['hub_sso_status'] ) ) {
				WPMUDEV_Dashboard::$settings->set( 'userid', get_current_user_id(), 'sso' );
			}
		}

		// always get projects on first login / sync.
		WPMUDEV_Dashboard::$api->refresh_projects_data();

		$result = WPMUDEV_Dashboard::$api->hub_sync( false, true );
		if ( ! $result || empty( $result['membership'] ) ) {
			// Return to previous key to avoid logout.
			WPMUDEV_Dashboard::$api->set_key( $previous_key );

			if ( false === $result ) {
				$this->send_json_error(
					array(
						'type'    => 'connection_error',
						'message' => __( 'Your server had a problem connecting to WPMU DEV.', 'wpmudev' ),
					)
				);
			}
			$this->send_json_error(
				array(
					'type'    => 'invalid_key',
					'message' => __( 'Your API Key was invalid.', 'wpmudev' ),
				)
			);
		}

		// Valid key.
		global $current_user;
		WPMUDEV_Dashboard::$settings->set( 'limit_to_user', array( $current_user->ID ), 'general' );
		WPMUDEV_Dashboard::$api->refresh_profile();

		// In case timeout use ?skip_upgrade_free_plugins.
		if ( $skip_free_upgrade ) {
			$this->send_json_success(
				array(
					'skip_upgrade_free_plugins' => true,
				)
			);
		}

		// Sync free plugins!, time execution will vary depends on installed plugins and server connection.
		$upgraded_plugins = array();
		$type             = WPMUDEV_Dashboard::$api->get_membership_status();
		if ( 'full' === $type || 'unit' === $type ) {
			$installed_free_projects = WPMUDEV_Dashboard::$site->get_installed_free_projects();

			foreach ( $installed_free_projects as $installed_free_project ) {
				$upgraded_plugin = array(
					'pid'         => $installed_free_project['id'],
					'name'        => $installed_free_project['name'],
					'is_upgraded' => false,
				);
				if ( WPMUDEV_Dashboard::$site->maybe_replace_free_with_pro( $installed_free_project['id'], false ) ) {
					$upgraded_plugin['is_upgraded'] = true;
				}

				$upgraded_plugins[] = $upgraded_plugin;
			}
		}

		$this->send_json_success(
			array(
				'skip_upgrade_free_plugins' => false,
				'upgrade_free_plugins'      => $upgraded_plugins,
			)
		);
	}

	/**
	 * Clear all output buffers and send an JSON success response.
	 *
	 * @since 4.11.6
	 *
	 * @param mixed $data Data to return.
	 */
	private function send_json_success( $data = null ) {
		$this->send_json( true, $data );
	}

	/**
	 * Clear all output buffers and send an JSON error response.
	 *
	 * @since 4.11.6
	 *
	 * @param mixed $data Data to return.
	 */
	private function send_json_error( $data = null ) {
		$this->send_json( false, $data );
	}

	/**
	 * Clear all output buffers and send an JSON response.
	 *
	 * @since 4.11.6
	 *
	 * @param bool  $success Is success.
	 * @param mixed $data    Optional data to return to the Ajax request.
	 */
	private function send_json( bool $success = true, $data = null ) {
		while ( ob_get_level() ) {
			ob_get_clean();
		}

		$success ? wp_send_json_success( $data ) : wp_send_json_error( $data );
	}
}