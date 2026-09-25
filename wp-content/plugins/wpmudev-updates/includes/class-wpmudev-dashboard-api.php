<?php
/**
 * API module.
 * Handles all functions that are doing or processing remote calls.
 *
 * @since   4.0.0
 * @package WPMUDEV_Dashboard
 */

/**
 * The main API class.
 */
class WPMUDEV_Dashboard_Api {

	/**
	 * Expiry time of the token used for Single SignOn, in seconds.
	 * If the token returned from the DEV site is older than that, the user won't be logged in.
	 */
	const SSO_TOKEN_EXPIRY_TIME = 30.0;

	/**
	 * The WPMUDEV API server.
	 *
	 * @var string (URL)
	 */
	protected string $server_root = 'https://wpmudev.com/';

	/**
	 * Path to the REST API on the server.
	 *
	 * @var string (URL)
	 */
	protected string $rest_api = 'api/dashboard/v2/';

	/**
	 * Path to the Analytics REST API on the server.
	 *
	 * @var string (URL)
	 */
	protected string $rest_api_analytics = 'api/analytics/v1/';

	/**
	 * Path to the Analytics REST API on the server.
	 *
	 * @var string (URL)
	 */
	protected string $rest_api_translation = 'api/translations/v1/';

	/**
	 * The complete WPMUDEV REST API endpoint. Defined in constructor.
	 *
	 * @var string (URL)
	 */
	protected string $server_url = '';

	/**
	 * Stores the API key used for authentication.
	 *
	 * @var string
	 */
	protected string $api_key = '';

	/**
	 * Stores the site_id from the API.
	 *
	 * @var int
	 */
	protected int $api_site_id = 0;

	/**
	 * Holds the last API error that occured (if any)
	 *
	 * @var string
	 */
	public string $api_error = '';

	/**
	 * Holds calls that already running in a routine
	 *
	 * @var array
	 */
	private static array $ran_calls = array();

	/**
	 * Set up the API module.
	 *
	 * @since 4.0.0
	 * @internal
	 */
	public function __construct() {
		if ( defined( 'WPMUDEV_CUSTOM_API_SERVER' ) && WPMUDEV_CUSTOM_API_SERVER ) {
			$this->server_root = trailingslashit( WPMUDEV_CUSTOM_API_SERVER );
		}
		$this->server_url = $this->server_root . $this->rest_api;

		if ( defined( 'WPMUDEV_APIKEY' ) && WPMUDEV_APIKEY ) {
			$this->api_key = WPMUDEV_APIKEY;
		} else {
			$this->api_key = get_site_option( 'wpmudev_apikey' );
		}

		// Schedule automatic data update on the main site of the network.
		if ( is_main_site() ) {
			if ( ! wp_next_scheduled( 'wpmudev_scheduled_jobs' ) ) {
				wp_schedule_event( time(), 'twicedaily', 'wpmudev_scheduled_jobs' );
			}

			// Run action on wpmudev admin actions.
			add_action( 'wpmudev_dashboard_admin_request', array( $this, 'run_admin_cron' ) );

			add_action( 'wpmudev_scheduled_jobs', array( $this, 'refresh_projects_data' ) );
			add_action( 'wpmudev_scheduled_jobs', array( $this, 'cron_hub_sync' ) );
			add_action( 'wpmudev_scheduled_jobs', array( $this, 'maybe_update_translations' ) );
		} elseif ( wp_next_scheduled( 'wpmudev_scheduled_jobs' ) ) {
			// In case the cron job was already installed in a sub-site...
			wp_clear_scheduled_hook( 'wpmudev_scheduled_jobs' );
		}

		/**
		 * Run custom initialization code for the API module.
		 *
		 * @since  4.0.0
		 *
		 * @param WPMUDEV_Dashboard_Api $api The dashboards API module.
		 */
		do_action( 'wpmudev_dashboard_api_init', $this );
	}


	/*
	 * *********************************************************************** *
	 * *     PUBLIC INTERFACE FOR OTHER MODULES
	 * *********************************************************************** *
	 */


	/**
	 * Returns true if the API key is defined.
	 *
	 * @since  4.0.0
	 * @return bool
	 */
	public function has_key(): bool {
		return ! empty( $this->api_key );
	}

	/**
	 * Returns the API key.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public function get_key(): string {
		return $this->api_key;
	}

	/**
	 * Updates the API key in the database.
	 *
	 * @since 4.0.0
	 *
	 * @param string $key The new API key to store.
	 */
	public function set_key( string $key ) {
		$this->api_key = $key;
		update_site_option( 'wpmudev_apikey', $key );
	}

	/**
	 * Returns the Hub Site ID.
	 *
	 * We just need this get method for this because
	 * it comes with membershipdata which is handled
	 * set/cleared on hubsync.
	 *
	 * @since  4.7.4
	 *
	 * @return int
	 */
	public function get_site_id(): int {
		// Do this here since we don't need it in construct.
		if ( ! $this->api_site_id ) {
			// Careful while using this.
			// Manually changing site ID could break your site and hub connection.
			// This is only for advance usage.
			if ( defined( 'WPMUDEV_SITE_ID' ) && WPMUDEV_SITE_ID ) {
				$this->api_site_id = (int) WPMUDEV_SITE_ID;
			} else {
				$membership = $this->get_membership_data();
				if ( ! empty( $membership ) && isset( $membership['hub_site_id'] ) ) {
					$this->api_site_id = (int) $membership['hub_site_id'];
				}
			}
		}

		return $this->api_site_id;
	}

	/**
	 * Returns the canonical site_url that should be used for the site in the hub.
	 *
	 * Define WPMUDEV_HUB_SITE_URL to override or make static the url it should show as
	 *  in the hub. Defaults to network_site_url() which may be dynamically filtered
	 *  by some plugins and hosting providers.
	 *
	 * @since  4.6.0
	 *
	 * @return string
	 */
	public function network_site_url(): string {
		return defined( 'WPMUDEV_HUB_SITE_URL' ) ? WPMUDEV_HUB_SITE_URL : network_site_url();
	}

	/**
	 * Returns the canonical home_url that should be used for the site in the hub.
	 *
	 * Define WPMUDEV_HUB_HOME_URL to override or make static the url it should show as
	 *  in the hub. Defaults to WPMUDEV_HUB_SITE_URL if set, or network_home_url() which may be dynamically filtered
	 *  by some plugins and hosting providers.
	 *
	 * @since  4.6.0
	 *
	 * @return string
	 */
	public function network_home_url(): string {
		if ( defined( 'WPMUDEV_HUB_HOME_URL' ) ) {
			return WPMUDEV_HUB_HOME_URL;
		} elseif ( defined( 'WPMUDEV_HUB_SITE_URL' ) ) {
			return WPMUDEV_HUB_SITE_URL;
		} else {
			return network_home_url();
		}
	}

	/**
	 * Returns the canonical home_url that should be used for the site in the hub.
	 *
	 * Define WPMUDEV_HUB_ADMIN_URL to override or make static the url it should show as
	 *  in the hub. Defaults to deriving from WPMUDEV_HUB_SITE_URL if set, or network_admin_url() which may be dynamically filtered
	 *  by some plugins and hosting providers.
	 *
	 * @since  4.6.0
	 *
	 * @return string
	 */
	public function network_admin_url(): string {
		if ( defined( 'WPMUDEV_HUB_ADMIN_URL' ) ) {
			return WPMUDEV_HUB_ADMIN_URL;
		} elseif ( defined( 'WPMUDEV_HUB_SITE_URL' ) ) {
			return is_multisite() ? trailingslashit( WPMUDEV_HUB_SITE_URL ) . 'wp-admin/network/' : trailingslashit( WPMUDEV_HUB_SITE_URL ) . 'wp-admin/';
		} else {
			return network_admin_url();
		}
	}

	/**
	 * Returns a URL we use to validate connection to server. This is not an
	 * API endpoint and does not return any defined information. Only the
	 * HTTP-Status of the GET/POST response is validated.
	 *
	 * @since  4.0.0
	 * @return string
	 */
	public function get_test_url(): string {
		return $this->rest_url( 'test' );
	}

	/**
	 * Returns the full URL to the specified REST API endpoint.
	 *
	 * This is a function instead of making the property $server_url public so
	 * we have better control and overview of the requested pages:
	 * It's easy to add a filter or add extra URL params to all URLs this way.
	 *
	 * @since  4.0.0
	 *
	 * @param string $endpoint The endpoint to call on the server.
	 *
	 * @return string The full URL to the requested endpoint.
	 */
	public function rest_url( string $endpoint ): string {
		if ( preg_match( '!^https?://!', $endpoint ) ) {
			$url = $endpoint;
		} else {
			$url = $this->server_url . $endpoint;
		}

		return $url;
	}

	/**
	 * Returns the full URL to the specified REST API endpoint and includes
	 * the API key as last element in URL.
	 *
	 * Uses the function `rest_url()` to build the URL.
	 *
	 * @since  4.0.0
	 *
	 * @param string $endpoint The endpoint to call on the server.
	 *
	 * @return string The full URL to the requested endpoint.
	 */
	public function rest_url_auth( string $endpoint ): string {
		$api_key = $this->get_key();
		if ( false === strpos( $endpoint, '/' . $api_key ) ) {
			$endpoint .= '/' . $api_key;
		}

		$membership_data = $this->get_membership_data();
		if ( isset( $membership_data['hub_site_id'] ) ) {
			$endpoint .= '?site_id=';
			$endpoint .= $membership_data['hub_site_id'];
		}

		return $this->rest_url( $endpoint );
	}

	/**
	 * Checks if the specified URL is on our remote server.
	 *
	 * @since  4.0.0
	 *
	 * @param string $url The full URL to evaluate.
	 *
	 * @return bool True if the URL is on our remote server.
	 */
	public function is_server_url( string $url ): bool {
		return false !== strpos( $url, $this->server_url );
	}

	/**
	 * Process admin side actions if it's from cron.
	 *
	 * @since  4.11.6
	 * @access public
	 *
	 * @param array $data Request data.
	 *
	 * @return void
	 */
	public function run_admin_cron( array $data ) {
		if (
			isset( $data['action'], $data['from'] )
			&& 'cron' === $data['from']
			&& 'hub_sync' === $data['action']
		) {
			$return_true = fn() => true;
			add_filter( 'wp_doing_cron', $return_true );

			// Run hub sync.
			$this->hub_sync();

			remove_filter( 'wp_doing_cron', $return_true );
		}
	}

	/**
	 * Run cron hub sync using admin HTTP request.
	 *
	 * @since  4.11.6
	 * @access public
	 *
	 * @return void
	 */
	public function cron_hub_sync() {
		WPMUDEV_Dashboard::$utils->send_admin_request(
			array(
				'from'   => 'cron',
				'action' => 'hub_sync',
			)
		);
	}

	/**
	 * Makes an API call and returns the results.
	 *
	 * The remote_path can be either relative to the server_url or it can be
	 * an absolute URL to any server.
	 *
	 * If remote_path is a relative path then the API-Key is automatically
	 * added the URL.
	 *
	 * @since  4.0.0
	 *
	 * @param string $remote_path The API function to call.
	 * @param mixed  $data        Optional. GET or POST data to send.
	 * @param string $method      Optional. GET or POST.
	 * @param array  $options     Optional. Array of request options.
	 *
	 * @return array|WP_Error Results of the wp_remote_get/post call.
	 */
	public function call( string $remote_path, $data = false, string $method = 'GET', array $options = array() ) {
		$link = $this->rest_url( $remote_path ); // use $link, to avoid using same path transient but different remote/upstream server.

		$options = wp_parse_args(
			$options,
			array(
				'use_transient' => true,
				// 2 minutes = API upstream timeout is 60s + common user's http/webserver timeout is 60s
				'transient_ttl' => 2 * MINUTE_IN_SECONDS,
			),
		);

		/**
		 * Override Options for API calls.
		 *
		 * @since  5.0.0
		 *
		 * @param array       $options     Current API call options.
		 * @param string      $remote_path Remote Path of the API to call.
		 * @param array|false $data        Request data of the API to call.
		 * @param string      $method      Request data of the API to call.
		 */
		$options = apply_filters( 'wpmudev_api_call_options', $options, $remote_path, $data, $method );

		$use_transient = $options['use_transient'];
		unset( $options['use_transient'] );
		$transient_ttl = $options['transient_ttl'];
		unset( $options['transient_ttl'] );

		$call_hash = md5( wp_json_encode( array( $link, $data, $method, $options ) ) );
		if ( isset( self::$ran_calls[ $call_hash ] ) ) {
			return self::$ran_calls[ $call_hash ];
		}

		// pre-set locking.
		self::$ran_calls[ $call_hash ] = new WP_Error( 'currently_running', 'Same API call currently running.' );

		$transient_response = false;
		if ( $use_transient ) {
			$transient_response = WPMUDEV_Dashboard::$settings->get_transient( sprintf( 'api_call_%s', $call_hash ) );
		}

		if ( false !== $transient_response ) {
			$transient_response = is_array( $transient_response ) ? $transient_response : array();
			$transient_response = wp_parse_args(
				$transient_response,
				array(
					'type'    => 'wp_error',
					'data'    => array(),
					'code'    => 'empty_response',
					'message' => 'Empty Response',
				)
			);
			if ( 'wp_error' === $transient_response['type'] ) {
				self::$ran_calls[ $call_hash ] = new WP_Error( $transient_response['code'], $transient_response['message'], $transient_response['data'] );

				return self::$ran_calls[ $call_hash ];
			}

			self::$ran_calls[ $call_hash ] = $transient_response['data'];

			return self::$ran_calls[ $call_hash ];
		}

		$options = wp_parse_args(
			$options,
			array(
				'timeout'    => 15,
				'sslverify'  => defined( 'WPMUDEV_API_SSLVERIFY' ) && WPMUDEV_API_SSLVERIFY,
				'user-agent' => 'WPMUDEV Dashboard Client/' . WPMUDEV_Dashboard::$version . ' (+' . network_site_url() . ')',
			)
		);

		// Solve the annoying WordPress warning: "gzinflate(): data error".
		if ( defined( 'WPMUDEV_API_UNCOMPRESSED' ) && WPMUDEV_API_UNCOMPRESSED ) {
			$options['decompress'] = false;
		}

		if ( ! isset( $options['headers'] ) ) {
			$options['headers'] = array();
		}

		/**
		 * Override Response of API calls.
		 * Non-null value will short-circuit the API call and return the value.
		 *
		 * @since  5.0.0
		 *
		 * @param mixed       $result      Result of API call. null, WP_Error, array.
		 * @param string      $remote_path Remote Path of the API to call.
		 * @param array|false $data        Request data of the API to call.
		 * @param string      $method      Request data of the API to call.
		 * @param array       $options     Current API call options.
		 */
		$response = apply_filters( 'wpmudev_api_call_response', null, $remote_path, $data, $method, $options );

		if ( ! is_null( $response ) ) {
			return $response;
		}

		if ( 'GET' === $method ) {
			if ( ! empty( $data ) ) {
				$link = add_query_arg( $data, $link );
			}
			$response = wp_remote_get( $link, $options );
		} elseif ( 'POST' === $method ) {
			$options['body'] = wp_json_encode( $data );
			if ( ! isset( $options['headers']['Content-Type'] ) ) {
				$options['headers']['Content-Type'] = 'application/json';
			}
			$response = wp_remote_post( $link, $options );
		} elseif ( 'DELETE' === $method ) {
			$options['body']   = wp_json_encode( $data );
			$options['method'] = 'DELETE';
			if ( ! isset( $options['headers']['Content-Type'] ) ) {
				$options['headers']['Content-Type'] = 'application/json';
			}
			$response = wp_remote_request( $link, $options );
		}

		// Add the request-URL to the response data.
		if ( $response && is_array( $response ) ) {
			$response['request_url'] = $link;
		}

		$transient_response = array(
			'type' => is_wp_error( $response ) ? 'wp_error' : 'response',
		);
		if ( is_wp_error( $response ) ) {
			$transient_response['code']    = $response->get_error_code();
			$transient_response['message'] = $response->get_error_message();
			$transient_response['data']    = $response->get_error_data();
		} else {
			$transient_response['data'] = $response;
		}

		if ( $use_transient ) {
			WPMUDEV_Dashboard::$settings->set_transient( sprintf( 'api_call_%s', $call_hash ), $transient_response, $transient_ttl );
		}

		if ( defined( 'WPMUDEV_API_DEBUG' ) && WPMUDEV_API_DEBUG ) {
			$log = '[WPMUDEV API call] %s | %s: %s (%s)';
			if ( defined( 'WPMUDEV_API_DEBUG_ALL' ) && WPMUDEV_API_DEBUG_ALL ) {
				$log .= "\nRequest options: %s\nResponse: %s";
			}

			// strip down big vars unless WPMUDEV_API_DEBUG_CRAZY is defined.
			$resp_body = wp_remote_retrieve_body( $response );
			if ( ! defined( 'WPMUDEV_API_DEBUG_CRAZY' ) || ! WPMUDEV_API_DEBUG_CRAZY ) {
				$req_body = $options['body'] ?? array();
				if ( ! is_array( $req_body ) ) {
					// most likely json encoded.
					$req_body = json_decode( $req_body, true );
					$req_body = is_array( $req_body ) ? $req_body : array(); // fool-proof for next array operations.
				}
				if ( isset( $req_body['projects'] ) ) {
					$req_body['projects'] = count( json_decode( $req_body['projects'], true ) ) . ' PROJECTS';
				}
				if ( isset( $req_body['repo_updates'] ) ) {
					$repo_updates             = json_decode( $req_body['repo_updates'], true );
					$req_body['repo_updates'] = array(
						'plugins' => count( $repo_updates['plugins'] ?? array() ),
						'themes'  => count( $repo_updates['themes'] ?? array() ),
					);
				}
				if ( isset( $req_body['packages'] ) ) {
					$packages             = json_decode( $req_body['packages'], true );
					$req_body['packages'] = array(
						'plugins' => count( $packages['plugins'] ?? array() ),
						'themes'  => count( $packages['themes'] ?? array() ),
					);
				}
				$options['body'] = $req_body;

				$resp_body = json_decode( wp_remote_retrieve_body( $response ) );
				if ( is_object( $resp_body ) ) {
					if ( isset( $resp_body->projects ) ) {
						$resp_body->projects = '[...]';
					}
					if ( isset( $resp_body->plugin_tags ) ) {
						$resp_body->plugin_tags = '[...]';
					}
				}
				$resp_body = wp_json_encode( $resp_body );
			}

			if ( $response && is_array( $response ) ) {
				$debug_data = sprintf( "%s %s\n", wp_remote_retrieve_response_code( $response ), wp_remote_retrieve_response_message( $response ) );
				// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export
				$debug_data .= var_export( wp_remote_retrieve_headers( $response ), true ) . PHP_EOL;
				// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_var_export
				$debug_data .= $resp_body;
			} else {
				$debug_data = '';
			}

			$backtrace = array();
			if ( defined( 'WPMUDEV_API_DEBUG_CRAZY' ) && WPMUDEV_API_DEBUG_CRAZY ) {
				$log .= "\nBacktrace: %s";

				$backtrace = wp_debug_backtrace_summary( null, 0, false ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_wp_debug_backtrace_summary
			}

			$msg = sprintf(
				$log,
				WPMUDEV_Dashboard::$version,
				$method,
				$link,
				wp_remote_retrieve_response_code( $response ),
				wp_json_encode( $options ),
				$debug_data,
				wp_json_encode( $backtrace, JSON_PRETTY_PRINT )
			);
			error_log( $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// set the $response for next call.
		self::$ran_calls[ $call_hash ] = $response;

		return self::$ran_calls[ $call_hash ];
	}

	/**
	 * Makes an API call and includes the API key in the REST URL and returns
	 * the results.
	 *
	 * Uses `call()` to get the results.
	 *
	 * @since  4.0.0
	 *
	 * @param string $remote_path The API function to call.
	 * @param mixed  $data        Optional. GET or POST data to send.
	 * @param string $method      Optional. GET or POST.
	 * @param array  $options     Optional. List of Request options.
	 *
	 * @return array Results of the wp_remote_get/post call.
	 */
	public function call_auth( string $remote_path, $data = false, string $method = 'GET', array $options = array() ) {
		if ( 'GET' === $method ) {
			if ( ! isset( $options['headers'] ) ) {
				$options['headers'] = array();
			}
			$options['headers']['Authorization'] = $this->get_key();
		} elseif ( 'POST' === $method || 'DELETE' === $method ) {
			if ( ! is_array( $data ) ) {
				$data = array();
			}

			$key_data            = array();
			$key_data['api_key'] = $this->get_key();

			// make sure api key is first.
			$data = array_merge( $key_data, $data );
		}

		return $this->call( $remote_path, $data, $method, $options );
	}

	/**
	 * In WP Engine hosting only requests from logged in users with auth cookies are given filesystem
	 *  write access. So we need to send those to Hub to allow for remote updates, backups, etc. Encrypted
	 *  for extra safety. Workaround inspired by ManageWP.
	 *
	 * @return array $cookies
	 */
	public function get_encrypted_cookies(): array {

		$crypt_file = WPMUDEV_Dashboard::$site->plugin_path . 'lib/PHPSecLib/Crypt/RSA.php';

		// we only need to run this in WP Engine environment.
		if ( ! defined( 'WPE_APIKEY' ) || ! is_readable( $crypt_file ) ) {
			return array();
		}

		$user_id = $this->get_first_admin_id();
		if ( ! $user_id ) {
			return array();
		}

		// Make sure the constants are set.
		$this->define_cookie_constants();

		$cookies = array();
		$secure  = is_ssl();
		// reuse internal WP filter.
		$secure = apply_filters( 'secure_auth_cookie', $secure, $user_id ); //phpcs:ignore

		if ( $secure ) {
			$auth_cookie_name = defined( 'SECURE_AUTH_COOKIE' ) ? SECURE_AUTH_COOKIE : uniqid( 'secure_auth_cookie', true );
			$scheme           = 'secure_auth';
		} else {
			$auth_cookie_name = defined( 'AUTH_COOKIE' ) ? AUTH_COOKIE : uniqid( 'auth_cookie', true );
			$scheme           = 'auth';
		}

		$expiration       = time() + ( DAY_IN_SECONDS * 14 ); // we expire sites from the hub after 14 days, so long enough for these cookies.
		$logged_in_cookie = defined( 'LOGGED_IN_COOKIE' ) ? LOGGED_IN_COOKIE : uniqid( 'logged_in_ookie', true );

		$cookies[ $auth_cookie_name ] = wp_generate_auth_cookie( $user_id, $expiration, $scheme );
		$cookies[ $logged_in_cookie ] = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in' );
		$cookies['wpe-auth']          = md5( 'wpe_auth_salty_dog|' . WPE_APIKEY ); // this is WP Engine's proprietary auth cookie.

		if ( ! class_exists( 'Crypt_RSA', false ) ) {
			require_once WPMUDEV_Dashboard::$site->plugin_path . 'lib/PHPSecLib/Crypt/RSA.php';
		}

		$rsa = new Crypt_RSA();
		$rsa->setEncryptionMode( CRYPT_RSA_SIGNATURE_PKCS1 );
		// load public key.
		$rsa->loadKey(
			file_get_contents( WPMUDEV_Dashboard::$site->plugin_path . 'keys/dashboard.pub' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			CRYPT_RSA_PUBLIC_FORMAT_PKCS1
		);

		foreach ( $cookies as &$cookie_value ) {
			$cookie_value = base64_encode( $rsa->encrypt( $cookie_value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		return $cookies;
	}

	/**
	 * Get first WP admin ID.
	 *
	 * @since 5.0.0
	 *
	 * @return int
	 */
	public function get_first_admin_id(): int {
		/**
		 * Pre-set first admin ID.
		 * Any non-null return will short-circuit the function, bypassing the automated traversal logic.
		 *
		 * @since  5.0.0
		 *
		 * @param null $admin_id User ID of first admin.
		 *
		 * @return ?int
		 */
		$user_id = apply_filters( 'wpmudev_dashboard_api_pre_first_admin_id', null );

		if ( ! is_null( $user_id ) ) {
			return $user_id;
		}

		$user_id = 0;

		if ( is_multisite() ) {
			// super admins on multisite.
			$super_admins = get_super_admins();
			foreach ( $super_admins as $super_admin ) {
				$user = get_user_by( 'login', $super_admin );
				if ( is_a( $user, 'WP_User' ) ) {
					$user_id = $user->ID;
					break;
				}
			}
		} else {
			// get from transient, get_users can be expensive in sites with very big users.
			$cached_first_admin_id = WPMUDEV_Dashboard::$settings->get_transient( 'first_admin_id' );
			if ( $cached_first_admin_id ) {
				$user = get_userdata( $cached_first_admin_id );
				if ( is_a( $user, 'WP_User' ) && in_array( 'administrator', (array) ( $user->roles ?? array() ), true ) ) {
					$user_id = $user->ID;
				} else {
					WPMUDEV_Dashboard::$settings->set_transient( 'first_admin_id', null );
				}
			}

			// transient not found or no longer valid.
			if ( ! $user_id ) {
				$ids = get_users(
					array(
						'role'        => 'administrator',
						'number'      => 1, // only need 1.
						'fields'      => 'ids', // only need user id.
						'orderby'     => 'ID', // use the primary key for sorting, so its cheaper.
						'order'       => 'ASC', // use the default ASC sorting, so its cheaper.
						'count_total' => false, // doesn't care about count total.
					)
				);

				$user_id = ! empty( $ids ) ? (int) $ids[0] : 0;
				WPMUDEV_Dashboard::$settings->set_transient( 'first_admin_id', $user_id ); // transient indefinitely, it is fine because we revalidate on read.
			}
		}

		/**
		 * Override first admin ID.
		 *
		 * @since  5.0.0
		 *
		 * @param int $admin_id User ID of first admin.
		 *
		 * @return int
		 */
		return apply_filters( 'wpmudev_dashboard_api_first_admin_id', $user_id );
	}

	/**
	 * Defines cookie-related WordPress constants if required.
	 *
	 * In WP Engine, sometimes there is a delay so we try to access
	 * the constants before it's defined.
	 *
	 * @see   https://incsub.atlassian.net/browse/WDD-140
	 *
	 * @since 4.11.1
	 */
	private function define_cookie_constants() {
		// Include required file.
		if ( ! function_exists( 'wp_cookie_constants' ) ) {
			include_once ABSPATH . 'wp-includes/default-constants.php';
		}

		// Make sure the constants are defined by WP.
		wp_cookie_constants();
	}

	/**
	 * The proper way to get details about the current projects on DEV.
	 *
	 * @since  1.0.0
	 * @since  5.0.0 Remove conditional fetch / refresh, always relies on cached WP options. WDD-571.
	 * @return array {
	 *         Details about current projects on DEV.
	 *
	 * @type string $downloads         [disabled|enabled]
	 * @type array  $free_notice       Array with 'key' and 'msg'
	 * @type array  $full_notice       Array with 'key' and 'msg'
	 * @type array  $single_notice     Array with 'key' and 'msg'
	 * @type int    $latest_release    A Project-ID
	 * @type array  $latest_plugins    Array of latest 5 project-IDs
	 * @type array  $latest_themes     Array of latest 5 project-IDs
	 * @type array  $plugin_tags       List of all plugin tags with list of tagged projects
	 * @type array  $theme_tags        List of all theme tags with list of tagged projects
	 * @type array  $projects          Complete list of all available projects (plugins and themes)
	 * @type string $text_admin_notice HTML text for display
	 * @type string $text_page_head    HTML text for display
	 *                                 }
	 */
	public function get_projects_data(): array {
		$res = WPMUDEV_Dashboard::$settings->get( 'updates_data' );

		// Basic sanitation, to avoid incompatible return values.
		if ( ! is_array( $res ) ) {
			$res = array();
		}
		$res = wp_parse_args(
			$res,
			array(
				'latest_release' => 0,
				'latest_plugins' => array(),
				'latest_themes'  => array(),
				'plugin_tags'    => array(),
				'theme_tags'     => array(),
				'projects'       => array(),
			)
		);

		return apply_filters( 'wpmudev_dashboard_get_projects_data', $res );
	}

	/**
	 * The proper way to get details about the current membership
	 *
	 * @since  4.4.1
	 * @return array {
	 *         Details about current membership.
	 *
	 * @type string $membership            [free|single|unit|full]
	 * @type string $membership_full_level [gold|bronze|silver]
	 *                                     }
	 */
	public function get_membership_data(): array {
		$res = WPMUDEV_Dashboard::$settings->get( 'membership_data' );
		// Basic sanitation, to avoid incompatible return values.
		if ( ! is_array( $res ) ) {
			$res = array();
		}
		$res = wp_parse_args(
			$res,
			array(
				'membership' => '',
			)
		);

		return apply_filters( 'wpmudev_dashboard_get_membership_data', $res );
	}

	/**
	 * Get the current membership type/status (deprecated).
	 *
	 * Possible return values:
	 * 'free'    - expired/not signed up yet.
	 * 'single'  - Single membership (i.e. only 1 project is licensed)
	 * 'unit'    - One or more projects licensed
	 * 'full'    - Full membership, no restrictions.
	 * 'free_hub'  - Free hub membership.
	 *
	 * @since      4.0.0
	 *
	 * @return string The membership type.
	 */
	public function get_membership_type(): string {
		// Get the current membership status.
		$type = $this->get_membership_status();

		// Available membership types.
		$types = array( 'full', 'unit', 'single' );

		if ( ! in_array( $type, $types, true ) ) {
			/**
			 * For backward compatibility.
			 * We previously considered expired, paused etc. types as free.
			 * But now we have a separate `free` type. But for backward compat
			 * we need to use different name for `free` type.
			 */
			$type = 'free' === $type ? 'free_hub' : 'free';
		}

		return $type;
	}

	/**
	 * Get current membership type/status.
	 *
	 * Possible return values:
	 * 'free'    - Free hub membership.
	 * 'single'  - Single membership (i.e. only 1 project is licensed)
	 * 'unit'    - One or more projects licensed
	 * 'full'    - Full membership, no restrictions.
	 * 'paused'  - Membership access is paused.
	 * 'expired' - Expired membership.
	 * ''        - (empty string) If user is not logged in or with an unknown type.
	 *
	 * @since 4.11.9
	 *
	 * @return string The membership type.
	 */
	public function get_membership_status(): string {
		$data = $this->get_membership_data();

		// Available membership types.
		$types = array(
			'full',
			'unit',
			'free',
			'paused',
			'expired',
		);

		// Default type is empty.
		$type = '';

		// All possible string values.
		if ( is_string( $data['membership'] ) && in_array( $data['membership'], $types, true ) ) {
			$type = $data['membership'];
		} elseif (
			is_numeric( $data['membership'] )
			|| ( is_bool( $data['membership'] ) && isset( $data['membership_full_level'] ) && is_numeric( $data['membership_full_level'] ) )
		) {
			$type = 'single';
		}

		return $type;
	}

	/**
	 * Returns a numeric id or array of numeric ids or projects available on plan.
	 * Numeric id is returned only if "single" plan is active, for backwards compatibility.
	 *
	 * This method does not account for membership_excluded_projects!!! Use:
	 * WPMUDEV_Dashboard::$api->get_excluded_projects()
	 * to exclude projects where needed.
	 *
	 * @since  4.9.0
	 *
	 * @return int[]|int Numeric id or available project for "single" plan or array or
	 *               numeric ids for "unit" plans.
	 */
	public function get_membership_projects() {
		$data = $this->get_membership_data();

		if ( 'full' === $data['membership'] ) {
			return array();
		}
		// For free and unit memberships.
		if ( in_array( $data['membership'], array( 'free', 'unit' ), true ) ) {
			$projects = is_array( $data['membership_projects'] ) ? $data['membership_projects'] : array();
			foreach ( $projects as $i => $p ) {
				$projects[ $i ] = intval( $p );
			}

			return $projects;
		}
		if ( is_numeric( $data['membership'] ) ) {
			return intval( $data['membership'] );
		}
		if ( is_bool( $data['membership'] ) && is_numeric( $data['membership_full_level'] ) ) {
			return intval( $data['membership_full_level'] );
		}

		return array();
	}

	/**
	 * Get projects that are strictly forbidden to be installed or updated for
	 * current membership level.
	 *
	 * @return int[] List of excluded project ids as numeric values.
	 */
	public function get_excluded_projects(): array {
		$key      = 'membership_excluded_projects';
		$defaults = array( $key => array() );
		$data     = wp_parse_args( $this->get_membership_data(), $defaults );

		$projects = array();
		if ( false === empty( $data[ $key ] ) ) {
			foreach ( $data[ $key ] as $pid ) {
				$projects[] = intval( $pid );
			}
		}

		return $projects;
	}

	/**
	 * Checks if feature is allowed for membership plan by feature string.
	 *
	 * @param string $feature Feature string.
	 *
	 * @return boolean is allowed.
	 */
	private function is_feature_allowed( string $feature ): bool {
		$data     = $this->get_membership_data();
		$features = $data['membership_access'] ?? array();

		// The membership_access can be boolean true for full accesss, or array with allowed features strings.
		if ( true === $features ) {
			return true;
		}

		if ( false === is_array( $features ) ) {
			return false;
		}

		return in_array( $feature, $features, true );
	}

	/**
	 * Checks if feature is allowed for membership plan by feature string.
	 *
	 * This is here for other plugins to check feature availability.
	 *
	 * @since 4.11.9
	 *
	 * @param string $feature Feature string.
	 *
	 * @return boolean is allowed.
	 */
	public function has_access( string $feature ): bool {
		return $this->is_feature_allowed( $feature );
	}

	/**
	 * Checks if whitelabel is allowed by membership plan.
	 *
	 * @return boolean is allowed.
	 */
	public function is_whitelabel_allowed(): bool {
		return $this->is_feature_allowed( 'whitelabel-dashboard' );
	}

	/**
	 * Checks if analytics is allowed by membership plan.
	 *
	 * @since 4.11
	 *
	 * @return boolean is allowed.
	 */
	public function is_analytics_allowed(): bool {
		return $this->is_feature_allowed( 'whitelabel-basic-analytics' );
	}

	/**
	 * Checks if support forum is allowed by membership plan.
	 *
	 * @since 4.11.8
	 *
	 * @return boolean is allowed.
	 */
	public function is_support_allowed(): bool {
		return $this->is_feature_allowed( 'support-forums' );
	}

	/**
	 * Checks if tickets are hidden on UI.
	 *
	 * @since 4.11.4
	 *
	 * @return bool is hidden.
	 */
	public function is_tickets_hidden(): bool {
		// Get membership data.
		$data = $this->get_membership_data();
		// Check tickets visibility.
		$hidden = isset( $data['is_tickets_hidden'] ) && $data['is_tickets_hidden'];

		/**
		 * Filter hook to change tickets visibility.
		 *
		 * @since 4.11.4
		 *
		 * @param bool $visible Is hidden.
		 */
		return apply_filters( 'wpmudev_dashboard_is_tickets_hidden', $hidden );
	}

	/**
	 * Returns the details of a single project from the API.
	 *
	 * @since  4.0.0
	 *
	 * @param int $project_id The project to return.
	 *
	 * @return array|false Project details.
	 */
	public function get_project_data( int $project_id ) {
		static $all_projects = null;

		$item = false;

		if ( null === $all_projects ) {
			$data = $this->get_projects_data();
			if ( isset( $data['projects'] ) ) {
				$all_projects = $data['projects'];
			}
		}

		if ( $all_projects && isset( $all_projects[ $project_id ] ) ) {
			$item = wp_parse_args(
				$all_projects[ $project_id ],
				array(
					'id'                => 0,
					'paid'              => 'paid',
					'type'              => 'plugin',
					'name'              => '',
					'released'          => 0,
					'updated'           => 0,
					'downloads'         => 0,
					'popularity'        => 0,
					'short_description' => '',
					'features'          => array(),
					'active'            => true,
					'version'           => '1.0.0',
					'autoupdate'        => 1,
					'requires'          => 'wp',
					'requires_min_php'  => '5.6',
					'compatible'        => '',
					'url'               => '',
					'thumbnail'         => '',
					'video'             => false,
					'wp_config_url'     => '',
					'ms_config_url'     => '',
					'package'           => 0,
					'screenshots'       => array(),
					'free_version_slug' => '',
					'is_plugin_addon'   => false,
				)
			);
		} elseif ( defined( 'WPMUDEV_API_DEBUG' ) && WPMUDEV_API_DEBUG && defined( 'WPMUDEV_API_DEBUG_CRAZY' ) ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'[WPMUDEV API Warning] No remote data found for project %s',
					$project_id
				)
			);
		}

		return $item;
	}

	/**
	 * Get available teams for the authenticated user.
	 *
	 * @since  4.11.10
	 *
	 * @param string $key User API key.
	 *
	 * @return array|bool
	 */
	public function get_user_teams( string $key ) {
		$options = array( 'use_transient' => false );
		// Sets up special auth header.
		$options['headers']                  = array();
		$options['headers']['Authorization'] = $key;

		// Send API request.
		$response = WPMUDEV_Dashboard::$api->call(
			'site-authenticate-teams',
			false,
			'GET',
			$options
		);

		// Error.
		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			$this->parse_api_error( $response );

			return false;
		} else {
			// Get team list.
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( isset( $data['data'] ) ) {
				return $data['data'];
			}
		}

		return array();
	}

	/**
	 * Returns a list of all plugins and themes installed the WordPress site
	 * WPMU DEV projects are not included here.
	 *
	 * @since  4.3.0
	 * @return array Array that contains 2 sub-arrays: 'plugins' and 'themes'.
	 */
	public function get_repo_packages(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$packages = array(
			'plugins' => array(),
			'themes'  => array(),
		);

		$plugins = get_plugins();
		$themes  = wp_get_themes();

		// First remove WPMUDEV plugins from the WP update data (for slug conflicts like.
		$local_projects = WPMUDEV_Dashboard::$site->get_cached_projects();
		foreach ( $local_projects as $update ) {
			if ( isset( $plugins[ $update['filename'] ] ) ) {
				unset( $plugins[ $update['filename'] ] );
			}

			$theme_slug = dirname( $update['filename'] );
			if ( isset( $themes[ $theme_slug ] ) ) {
				unset( $themes[ $theme_slug ] );
			}
		}

		// Extract and collect details we need.
		foreach ( $plugins as $slug => $data ) {
			// Only network active plugin should be considered as active.
			$active = is_multisite() ? is_plugin_active_for_network( $slug ) : is_plugin_active( $slug );

			$packages['plugins'][ $slug ] = array(
				'name'       => $data['Name'],
				'version'    => $data['Version'],
				'plugin_url' => $data['PluginURI'],
				'author'     => $data['Author'],
				'author_url' => $data['AuthorURI'],
				'network'    => $data['Network'],
				'active'     => $active,
			);
		}

		foreach ( $themes as $slug => $theme ) {
			if ( is_multisite() ) {
				$active = $theme->is_allowed() || get_stylesheet() === $slug; // network enabled or on main site.
			} else {
				// If the theme is available on main site it's "active".
				$active = get_stylesheet() === $slug;
			}

			$parent                      = $theme->parent() ? $theme->get_template() : false;
			$packages['themes'][ $slug ] = array(
				'name'       => $theme->display( 'Name', false ),
				'version'    => $theme->display( 'Version', false ),
				'author'     => $theme->display( 'Author', false ),
				'author_url' => $theme->display( 'AuthorURI', false ),
				'screenshot' => $theme->get_screenshot(),
				'parent'     => $parent,
				'active'     => $active,
			);
		}

		return $packages;
	}

	/**
	 * Returns a list of all plugins and themes on the WordPress site that have
	 * an pending update. WPMU DEV projects are not included here.
	 *
	 * @since  4.1.0
	 * @return array Array that contains 2 sub-arrays: 'plugins' and 'themes'.
	 */
	public function get_repo_updates_infos() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$core_updates = apply_filters( 'wpmudev_pre_get_repo_updates_infos', null );
		if ( ! is_null( $core_updates ) ) {
			return $core_updates;
		}
		$core_updates = array(
			'plugins' => array(),
			'themes'  => array(),
		);

		// Remove our custom filters, so we get the original updates list.
		remove_filter(
			'site_transient_update_plugins',
			array( WPMUDEV_Dashboard::$site, 'filter_plugin_update_count' )
		);
		remove_filter(
			'site_transient_update_themes',
			array( WPMUDEV_Dashboard::$site, 'filter_theme_update_count' )
		);

		$check_updates_attempted = false;
		$doing_cron              = function_exists( 'wp_doing_cron' ) && wp_doing_cron();
		// we can afford expensiveness, in cron ( non-interactive ).
		if ( $doing_cron ) {
			require_once ABSPATH . 'wp-includes/update.php';
			if ( function_exists( '_maybe_update_plugins' ) ) {
				_maybe_update_plugins();
			}
			if ( function_exists( '_maybe_update_themes' ) ) {
				_maybe_update_themes();
			}
			$check_updates_attempted = true;
		}

		// Get the available updates list.
		$plugin_data = get_site_transient( 'update_plugins' );
		$theme_data  = get_site_transient( 'update_themes' );

		// updates transient expected to be an object, when it isn't, it means it was deleted, usually due to WP core flow, and it ended up as `false`.
		// in that case, we are getting the data from snapshot.
		// in most if not all cases, the snapshot should be valid ( not stale ), as in WP core flow it usually do wp_update_plugins|themes.
		// ref: https://core.trac.wordpress.org/ticket/65543.
		// we could force WP core wp_update_plugins|themes routine here, but it could be expensive.
		if ( ! is_object( $plugin_data ) ) {
			$plugin_data = WPMUDEV_Dashboard::$site->get_plugin_updates_transient_snapshot();
			// last _expensive_ resort, trigger WP core routine.
			if ( ! is_object( $plugin_data ) && ! $check_updates_attempted ) {
				require_once ABSPATH . 'wp-includes/update.php';
				if ( function_exists( '_maybe_update_plugins' ) ) {
					_maybe_update_plugins();
					$plugin_data = get_site_transient( 'update_plugins' );
				}
			}
		}
		if ( ! is_object( $theme_data ) ) {
			$theme_data = WPMUDEV_Dashboard::$site->get_theme_updates_transient_snapshot();
			// last _expensive_ resort, trigger WP core routine.
			if ( ! is_object( $theme_data ) && ! $check_updates_attempted ) {
				require_once ABSPATH . 'wp-includes/update.php';
				if ( function_exists( '_maybe_update_themes' ) ) {
					_maybe_update_themes();
					$theme_data = get_site_transient( 'update_themes' );
				}
			}
		}

		// Restore our filters to include WPMU DEV projects in the updates list.
		add_filter(
			'site_transient_update_plugins',
			array( WPMUDEV_Dashboard::$site, 'filter_plugin_update_count' )
		);
		add_filter(
			'site_transient_update_themes',
			array( WPMUDEV_Dashboard::$site, 'filter_theme_update_count' )
		);

		// First remove WPMUDEV plugins from the WP update data (for slug conflicts like.
		$local_projects = WPMUDEV_Dashboard::$site->get_cached_projects();
		foreach ( $local_projects as $update ) {
			if ( isset( $plugin_data->response[ $update['filename'] ] ) ) {
				unset( $plugin_data->response[ $update['filename'] ] );
			}
			if ( isset( $plugin_data->no_update[ $update['filename'] ] ) ) {
				unset( $plugin_data->no_update[ $update['filename'] ] );
			}

			$theme_slug = dirname( $update['filename'] );
			if ( isset( $theme_data->response[ $theme_slug ] ) ) {
				unset( $theme_data->response[ $theme_slug ] );
			}
			if ( isset( $theme_data->no_update[ $theme_slug ] ) ) {
				unset( $theme_data->no_update[ $theme_slug ] );
			}
		}

		// Extract and collect details we need.
		if ( isset( $plugin_data->response ) && is_array( $plugin_data->response ) ) {
			foreach ( $plugin_data->response as $slug => $infos ) {
				$item                             = get_plugin_data( WP_PLUGIN_DIR . '/' . $slug );
				$core_updates['plugins'][ $slug ] = array(
					'name'        => $item['Name'],
					'version'     => $item['Version'],
					'new_version' => $infos->new_version,
					'upgradable'  => ! empty( $infos->package ),
				);
			}
		}

		if ( isset( $theme_data->response ) && is_array( $theme_data->response ) ) {
			foreach ( $theme_data->response as $slug => $infos ) {
				$item = wp_get_theme( $slug );
				// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$core_updates['themes'][ $slug ] = array(
					'name'        => $item->get( 'Name' ),
					'version'     => $item->get( 'Version' ),
					'new_version' => $infos['new_version'],
					'upgradable'  => ! empty( $infos['package'] ),
				);
				// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}

		return $core_updates;
	}

	/**
	 * The proper way to get the array of profile data from cache/Api.
	 *
	 * @since  1.0.0
	 * @return array
	 */
	public function get_profile() {
		$expire = time() - ( HOUR_IN_SECONDS );
		$flag   = WPMUDEV_Dashboard::$settings->get( 'refresh_profile', 'flags' );

		if ( $flag ) {
			WPMUDEV_Dashboard::$settings->set( 'profile_data', false );
			$res      = false;
			$last_run = 0;
		} else {
			$res      = WPMUDEV_Dashboard::$settings->get( 'profile_data' );
			$last_run = intval( WPMUDEV_Dashboard::$settings->get( 'last_run_profile', 'general' ) );
		}

		if ( $flag || ! $res || ! $last_run || $expire > $last_run ) {
			// This condition prevents race condition in case of network error
			// or problems on API side.
			if ( $last_run < time() ) {
				$res = $this->refresh_profile();
			}
		}

		// Basic sanitation, to avoid incompatible return values.
		if ( ! is_array( $res ) ) {
			$res = array();
		}
		if ( empty( $res['profile'] ) || ! is_array( $res['profile'] ) ) {
			$res['profile'] = array();
		}
		if ( empty( $res['points'] ) || ! is_array( $res['points'] ) ) {
			$res['points'] = array();
		}
		if ( empty( $res['forum'] ) ) {
			$res['forum'] = array();
		}
		if ( empty( $res['forum']['support_threads'] ) ) {
			$res['forum']['support_threads'] = array();
		}

		$res['profile'] = wp_parse_args(
			$res['profile'],
			array(
				'avatar'       => '',
				'member_since' => time(),
				'name'         => '[name]',
				'title'        => '[title]',
				'user_name'    => '[username]',
			)
		);
		$res['points']  = wp_parse_args(
			$res['points'],
			array(
				'hero_points' => 0,
				'history'     => array(),
				'rank'        => 0,
				'rep_points'  => 0,
			)
		);

		return $res;
	}

	/**
	 * The proper way to get a projects changelog from cache/Api.
	 * The changelog is stored in transients with expire date of 7 days.
	 *
	 * @since  4.0.0
	 *
	 * @param int          $pid          The Project ID.
	 * @param string|false $last_version Optional. The last version that must appear
	 *                                   in the changelog; used to refresh cached changelog data before
	 *                                   the cache expires.
	 *
	 * @return array
	 */
	public function get_changelog( int $pid, $last_version = false ) {
		$res = WPMUDEV_Dashboard::$settings->get_transient( 'changelog_' . $pid );

		if ( $last_version && is_array( $res ) && ! empty( $res[0] ) ) {
			$retry_stamp = time() - MINUTE_IN_SECONDS;

			if ( empty( $res['timestamp'] ) ) {
				$res = false;
			} elseif ( $res['timestamp'] <= $retry_stamp ) {
				// Check if version in cache is less then the latest version.
				if ( version_compare( $res[0]['version'], $last_version, 'lt' ) ) {
					$res = false; // Cache is outdated and needs to be refreshed.
				}
			}
		}

		if ( empty( $res ) || ! is_array( $res ) ) {
			$res = $this->refresh_changelog( $pid );
		}

		// Basic sanitation, to avoid incompatible return values.
		if ( ! is_array( $res ) ) {
			$res = array();
		}

		return $res;
	}


	/*
	 * *********************************************************************** *
	 * *     FETCH AND REFRESH DATA FROM API
	 * *********************************************************************** *
	 */


	/**
	 * Generates the stats data about the site and installed products
	 *
	 * @param bool       $encoded        Whether to json encode the fields that are arrays.
	 * @param bool|array $local_projects Optional array of local projects, pass if you have it to save time.
	 *
	 * @return array
	 */
	public function build_api_data( bool $encoded = false, $local_projects = false ): array {
		global $wp_version;

		if ( ! is_array( $local_projects ) ) {
			$local_projects = WPMUDEV_Dashboard::$site->get_cached_projects();
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$projects   = array();
		$theme      = wp_get_theme();
		$ms_allowed = $theme->get_allowed();
		foreach ( $local_projects as $pid => $item ) {
			if ( 'theme' === $item['type'] ) {
				$slug = dirname( $item['filename'] );
				if ( is_multisite() ) {
					$active = ! empty( $ms_allowed[ $slug ] ) || ( $theme->stylesheet === $slug || $theme->template === $slug ); // network enabled or on main site.
				} else {
					// If the theme is available on main site it's "active".
					$active = ( $theme->stylesheet === $slug || $theme->template === $slug );
				}
			} else {
				// On multisite, only consider network active plugins as active.
				$active = is_multisite() ? is_plugin_active_for_network( $item['filename'] ) : is_plugin_active( $item['filename'] );
			}

			$extra = '';

			/**
			 * Collect extra data from individual plugins.
			 *
			 * @since  4.0.0
			 *
			 * @param string $extra Default extra data is an empty string.
			 *
			 * @api    wpmudev_api_project_extra_data-$pid
			 */
			$extra = apply_filters( "wpmudev_api_project_extra_data_$pid", $extra );
			$extra = apply_filters( 'wpmudev_api_project_extra_data', $extra, $pid );

			$projects[ $pid ] = array(
				'version' => $item['version'],
				'active'  => (bool) $active,
				'extra'   => $extra,
			);
		}

		/**
		 * Allows modification of the plugin data that is sent to the server.
		 *
		 * @since  4.0.0
		 *
		 * @param array $projects The whole array of project details.
		 *
		 * @api    wpmudev_api_project_data
		 */
		$projects = apply_filters( 'wpmudev_api_project_data', $projects );

		// Get WP/BP version string to help with support.
		if ( is_multisite() ) {
			$wp_ver     = "WordPress Multisite $wp_version";
			$blog_count = get_blog_count();
		} else {
			$wp_ver     = "WordPress $wp_version";
			$blog_count = 1;
		}
		if ( defined( 'BP_VERSION' ) ) {
			$wp_ver .= ', BuddyPress ' . BP_VERSION;
		}

		// Prepare site info.
		$site_info = WPMUDEV_Dashboard::$utils->get_site_info();

		// Get a list of pending WP updates of non-WPMUDEV themes/plugins.
		$repo_updates = $this->get_repo_updates_infos();

		$packages = $this->get_repo_packages();

		// get auth cookies if in WP Engine.
		$auth_cookies = $this->get_encrypted_cookies();

		$call_version = WPMUDEV_Dashboard::$version;

		$data = array(
			'call_version' => $call_version,
			'domain'       => $this->network_site_url(),
			'blog_count'   => $blog_count,
			'wp_version'   => $wp_ver,
			'projects'     => $projects,
			'admin_url'    => $this->network_admin_url(),
			'home_url'     => $this->network_home_url(),
			'sso_status'   => WPMUDEV_Dashboard::$settings->get( 'enabled', 'sso' ),
			'repo_updates' => $repo_updates,
			'packages'     => $packages,
			'auth_cookies' => $auth_cookies,
			'site_info'    => $site_info,
		);

		// Report the hosting site_id if in WPMUDEV Hosting environment.
		$full_wpmu_dev_hosting_id = $this->get_full_wpmu_dev_hosting_id();
		if ( ! empty( $full_wpmu_dev_hosting_id ) ) {
			$data['hosting_site_id'] = $full_wpmu_dev_hosting_id;
		}

		if ( $encoded ) {
			$data['projects']     = wp_json_encode( $data['projects'] );
			$data['repo_updates'] = wp_json_encode( $data['repo_updates'] );
			$data['packages']     = wp_json_encode( $data['packages'] );
			$data['auth_cookies'] = wp_json_encode( $data['auth_cookies'] );
			$data['site_info']    = wp_json_encode( $data['site_info'] );
		}

		return $data;
	}

	/**
	 * Checks if site is hosted on WPMU Dev hosting.
	 *
	 * @since 4.9.0
	 * @since 4.11.15 Added extra checks.
	 * @since 4.11.30 Returns WPMU DEV hosting type passed by reference.
	 *
	 * @param string|null $type Passed by reference. Yields 'shared' or 'dedicated' depending on hosting type.
	 *
	 * @return bool Is site hosted on WPMU Dev, true if it is.
	 */
	public function is_wpmu_dev_hosting( ?string &$type = null ): bool {
		$type       = null;
		$is_hosting = defined( 'WPMUDEV_HOSTING_SITE_ID' ) || isset( $_SERVER['WPMUDEV_HOSTED'] );

		if ( $is_hosting ) {
			$type = ( defined( 'WPMUDEV_HOSTING_WEBSITE_ID' ) && ! empty( WPMUDEV_HOSTING_WEBSITE_ID ) ) ? 'shared' : 'dedicated';
		}

		return $is_hosting;
	}

	/**
	 * Get full WPMU DEV Hosting ID.
	 *
	 * @since 4.11.30
	 *
	 * @return string
	 */
	public function get_full_wpmu_dev_hosting_id(): string {
		if ( ! $this->is_wpmu_dev_hosting() ) {
			return '';
		}

		$server_id  = defined( 'WPMUDEV_HOSTING_SITE_ID' ) ? WPMUDEV_HOSTING_SITE_ID : gethostname();
		$website_id = ( defined( 'WPMUDEV_HOSTING_WEBSITE_ID' ) && ! empty( WPMUDEV_HOSTING_WEBSITE_ID ) ) ? WPMUDEV_HOSTING_WEBSITE_ID : '';

		if ( empty( $website_id ) ) { // non shared sub-hosting.
			return $server_id; // return server id.
		}

		return implode( '-', array( $website_id, $server_id ) );
	}

	/**
	 * Checks if site is hosted on WPMU Dev hosting with standalone hosting plan.
	 *
	 * @since 4.11.15 Added extra checks.
	 *
	 * @return bool
	 */
	public function is_standalone_hosting_plan(): bool {
		// Get membership data.
		$data = $this->get_membership_data();
		// For standalone hosting there should be active products.
		if ( isset( $data['membership_active_products'] ) && is_array( $data['membership_active_products'] ) ) {
			foreach ( $data['membership_active_products'] as $product ) {
				// If hosting plan found return early.
				if ( strpos( $product, 'hosting-' ) === 0 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Checks if current site is a third party site with standalone hosting plan.
	 *
	 * @since 4.11.15
	 *
	 * @return bool
	 */
	public function is_hosted_third_party(): bool {
		return $this->is_standalone_hosting_plan() && ! $this->is_wpmu_dev_hosting();
	}

	/**
	 * Contacts the API to sync the latest data from this site.
	 *
	 * Returns the membership status if things are working out.
	 * In case the API call fails the function returns boolean false and does
	 * not update the update
	 *
	 * @since    1.0.0
	 * @since    5.0.1 By default it respect transient.
	 *
	 * @param bool|array $local_projects Optional array of local projects.
	 * @param bool       $force          Optional forces a sync.
	 * @param ?bool      $use_transient  Optional Use transient. Default depends on data hash.
	 *
	 * @return array|bool
	 * @internal Function only is public because it's an action handler.
	 */
	public function hub_sync( $local_projects = false, bool $force = false, ?bool $use_transient = null ) {
		$res = false;

		/*
		Note: This endpoint does not require an API key.
		 */

		if ( defined( 'WP_INSTALLING' ) ) {
			return false;
		}

		// Clear the "Force data update" flag to avoid infinite loop.
		WPMUDEV_Dashboard::$settings->set( 'refresh_remote', false, 'flags' );

		// no key, no hub sync.
		if ( ! $this->has_key() ) {
			return false;
		}

		if ( defined( 'WPMUDEV_API_DEBUG_CRAZY' ) && WPMUDEV_API_DEBUG_CRAZY ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				wp_json_encode(
					array(
						__METHOD__,
						array(
							'force'               => $force,
							'shutdown_backtraces' => WPMUDEV_Dashboard::$site::$refresh_shutdown_backtraces,
							'backtrace'           => wp_debug_backtrace_summary( null, 0, false ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_wp_debug_backtrace_summary
						),
					),
					JSON_PRETTY_PRINT
				)
			);
		}

		$stats_data = $this->build_api_data( true, $local_projects );
		$hash_data  = $stats_data;

		unset( $hash_data['auth_cookies'] );
		$data_hash = md5( wp_json_encode( $hash_data ) ); // get a hash of the data to see if it changed (minus auth cookies).
		unset( $hash_data );

		$last_run = WPMUDEV_Dashboard::$settings->get( 'last_run_sync', 'general', array() );
		$last_run = is_array( $last_run ) ? $last_run : array();
		$last_run = wp_parse_args(
			$last_run,
			array(
				'fails' => 0,
				'hash'  => '',
				'time'  => 0,
			)
		);

		$is_identical_hash = hash_equals( $data_hash, $last_run['hash'] );

		if ( ! $force ) {
			// this is the main check to prevent pinging unless the data is changed or 6 hrs have passed.
			if ( $is_identical_hash && $last_run['time'] > ( time() - ( HOUR_IN_SECONDS * 6 ) ) ) {
				if ( defined( 'WPMUDEV_API_DEBUG' ) && WPMUDEV_API_DEBUG ) {
					error_log( '[WPMUDEV API] Skipped sync due to unchanged local data.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}

				return $this->get_membership_data();
			} elseif ( $last_run['fails'] ) { // check for exponential backoff.
				$backoff = min( pow( 5, $last_run['fails'] ), HOUR_IN_SECONDS ); // iterate into: 5, 25, 125, 625, 3125, 3600 max.
				if ( $last_run['time'] > ( time() - $backoff ) ) {
					if ( defined( 'WPMUDEV_API_DEBUG' ) && WPMUDEV_API_DEBUG ) {
						error_log( '[WPMUDEV API] Skipped sync due to API error exponential backoff.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}

					return $this->get_membership_data();
				}
			}
		}

		$stats_data['sync_version'] = WPMUDEV_Dashboard::$version;

		if ( is_null( $use_transient ) ) {
			$use_transient = $is_identical_hash; // use transient only if data is identical. fool-proof, API_CALL already has its own hash checks.
		}

		$response = WPMUDEV_Dashboard::$api->call_auth(
			'hub-sync',
			$stats_data,
			'POST',
			array(
				'use_transient' => $use_transient,
				'transient_ttl' => 5, // 5 seconds super shorts ttl.
			),
		);

		// eagerly set timer pointer.
		WPMUDEV_Dashboard::$settings->set(
			'last_run_sync',
			array(
				'time'  => time(),
				'hash'  => $data_hash,
				'fails' => 0,
			),
			'general'
		);

		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) ) {
				if ( isset( $data['membership'] ) && empty( $data['membership'] ) && ! defined( 'WPMUDEV_APIKEY' ) && WPMUDEV_Dashboard::$api->has_key() ) {
					// expired membership will also responded with `empty` membership. Refer to https://wpmudev.com/ to further identification.
					if ( defined( 'WPMUDEV_API_DEBUG' ) && WPMUDEV_API_DEBUG ) {
						error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							'[WPMUDEV API Warning] Invalid API key or Expired membership, logging out.'
						);
					}
					// clean it up.
					WPMUDEV_Dashboard::$api->set_key( '' ); // this has to be first, so any hook that potentially calls API and requires key won't be triggered.
					WPMUDEV_Dashboard::$settings->reset();
					WPMUDEV_Dashboard::$settings->set( 'membership_data', array() );
					WPMUDEV_Dashboard::$settings->set( 'connected_admin', 0, 'general' );

					// bail asap.
					return $data;
				}

				WPMUDEV_Dashboard::$settings->set( 'membership_data', $data );

				// Sync analytics state.
				$prev_analytics_data = array(
					'site_id'    => (int) WPMUDEV_Dashboard::$settings->get( 'site_id', 'analytics' ),
					'tracker'    => (string) WPMUDEV_Dashboard::$settings->get( 'tracker', 'analytics' ),
					'enabled'    => wp_validate_boolean( WPMUDEV_Dashboard::$settings->get( 'enabled', 'analytics' ) ),
					'script_url' => (string) WPMUDEV_Dashboard::$settings->get( 'script_url', 'analytics' ),
				);

				$new_analytics_data = array(
					'site_id'    => (int) ( $data['analytics_site_id'] ?? $prev_analytics_data['site_id'] ),
					'tracker'    => (string) ( $data['analytics_tracker'] ?? $prev_analytics_data['tracker'] ),
					'enabled'    => wp_validate_boolean( $data['analytics_enabled'] ?? $prev_analytics_data['enabled'] ),
					'script_url' => (string) ( $data['analytics_script_url'] ?? $prev_analytics_data['script_url'] ),
				);

				$analytics_updated = false;
				foreach ( $new_analytics_data as $key => $value ) {
					WPMUDEV_Dashboard::$settings->set( $key, $value, 'analytics' );
					if ( ! $analytics_updated && ( $prev_analytics_data[ $key ] ?? null ) !== $value ) {
						$analytics_updated = true;
					}
				}

				if ( $analytics_updated ) {
					$this->maybe_clear_hosting_static_cache();
				}

				$res = $data;
			} else {
				$this->parse_api_error( 'Error unserializing remote response.' );
			}
		} else {
			$this->parse_api_error( $response );

			// Check specifically for whether the user has exceeded the sites they can add to the Hub, due to being on the single site plan.
			$body = is_array( $response )
				? wp_remote_retrieve_body( $response )
				: false;

			$data = array();
			if ( is_array( $response ) && ! empty( $body ) ) {
				$data = json_decode( $body, true );
			}

			if ( isset( $data['code'] ) ) {
				$res = array();
				if ( 'limit_exceeded_no_hosting_sites' === $data['code'] ) {
					$res['limit_exceeded_no_hosting_sites'] = true;
				}
				$res['limit_data'] = $data['data'];
			}

			/*
			 * For network errors, perform exponential backoff
			 */
			$last_run['time']  = time();
			$last_run['fails'] = $last_run['fails'] + 1;
			WPMUDEV_Dashboard::$settings->set( 'last_run_sync', $last_run, 'general' );
		}

		return $res;
	}

	/**
	 * Unsync / disconnect / logout
	 *
	 * @return array|WP_Error
	 */
	public function hub_unsync() {
		$response = $this->call_auth(
			'hub-unsync',
			array(
				'domain'       => $this->network_site_url(),
				'sync_version' => WPMUDEV_Dashboard::$version,
			),
			'DELETE',
			array(
				'use_transient' => false,
			),
		);

		// to trigger logging.
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$this->parse_api_error( $response );
		}

		return $response;
	}

	/**
	 * Contacts the API to get the latest API updates data.
	 *
	 * Returns the available update details if things are working out.
	 * In case the API call fails the function returns boolean false and does
	 * not update the update
	 *
	 * @since    4.4.1
	 * @since    5.0.0 Introduce $allow_no_key
	 * @since    5.0.1 Make the function void-no-return, use get_projects_data() to get the data.
	 *
	 * @param bool $allow_no_key Whether execution allowed without key. Default false.
	 *
	 * @return void
	 * @internal Function only is public because it's an action handler.
	 */
	public function refresh_projects_data( bool $allow_no_key = false ) {
		if ( defined( 'WP_INSTALLING' ) ) {
			return;
		}

		// Clear the "Force data update" flag to avoid infinite loop.
		WPMUDEV_Dashboard::$settings->set( 'refresh_remote', false, 'flags' );

		// projects technically isn't authed API. But _if_ we decided that disconnected sites doesn't need to show updates, then this call serve no purpose when api key not there ( disconnected ).
		if ( ! $allow_no_key && ! $this->has_key() ) {
			return;
		}

		// if it's doing cron ( not manual trigger ), check for the last updates.
		// our cron is each 12 hrs ( twicedaily ).
		// lets set threshold of 10hrs.
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			$last_update = WPMUDEV_Dashboard::$settings->get( 'last_run_updates', 'general', 0 );
			if ( ! empty( $last_update ) && ( time() - $last_update ) < 10 * HOUR_IN_SECONDS ) {
				return;
			}
		}

		// we don't want/need to add apikey to this as we pass no data, and want CDN to cache it as a whole.
		$response = WPMUDEV_Dashboard::$api->call( 'projects' );

		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) ) {

				// Default order to display plugins is the order in the array.
				if ( isset( $data['projects'] ) ) {
					$pos = 1;
					foreach ( $data['projects'] as $id => $project ) {
						$data['projects'][ $id ]['_order'] = $pos;

						++$pos;
					}
				}

				// Remove projects that are not accessible for current member.
				$data = $this->strip_unavailable_projects( $data );

				WPMUDEV_Dashboard::$settings->set( 'updates_data', $data );
				WPMUDEV_Dashboard::$settings->set( 'last_run_updates', time(), 'general' );
				$this->calculate_upgrades();
				$this->calculate_translation_upgrades( true );

				return;
			}

			$this->parse_api_error( 'Error unserializing remote response.' );

			return;
		}

		$this->parse_api_error( $response );

		/*
		 * For network errors, set last run to 1 hour in future so it
		 * doesn't retry every single pageload (in case of server
		 * connection issues)
		 */
		WPMUDEV_Dashboard::$settings->set(
			'last_run_updates',
			time() + HOUR_IN_SECONDS,
			'general'
		);
	}

	/**
	 * Refresh the user profile in the local cache and return it.
	 *
	 * If there is any error while loading the current profile from the API
	 * the function will return boolean false and not update the cache.
	 *
	 * @since  1.0.0
	 * @return array|bool
	 */
	public function refresh_profile() {
		$res = false;

		/*
		Note: We need a VALID API KEY to access this endpoint.
		 */

		if ( defined( 'WP_INSTALLING' ) ) {
			return false;
		}
		if ( ! $this->has_key() ) {
			return false;
		}

		WPMUDEV_Dashboard::$settings->set( 'refresh_profile', false, 'flags' );

		$response = WPMUDEV_Dashboard::$api->call_auth( 'user-info' );

		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) ) {
				// 3.1.2 - 2012-06-26 PaulM Convert image urls for ssl admin
				if ( is_ssl() && isset( $data['profile']['gravatar'] ) ) {
					$data['profile']['gravatar'] = str_replace(
						'http://',
						'https://',
						$data['profile']['gravatar']
					);
				}

				WPMUDEV_Dashboard::$settings->set( 'profile_data', $data );
				WPMUDEV_Dashboard::$settings->set( 'last_run_profile', time(), 'general' );

				if ( ! empty( $data['profile']['user_name'] ) ) {
					// The only place we use this, is the login form.
					WPMUDEV_Dashboard::$settings->set(
						'auth_user',
						$data['profile']['user_name'],
						'general'
					);
				}

				$res = $data;
			} else {
				$this->parse_api_error( 'Error unserializing remote response.' );
			}
		} else {
			$this->parse_api_error( $response );
		}

		/*
		 * For network errors, set last run to 1 hour in future so it
		 * doesn't retry every single pageload (in case of server
		 * connection issues)
		 */
		WPMUDEV_Dashboard::$settings->set(
			'last_run_profile',
			time() + HOUR_IN_SECONDS,
			'general'
		);

		return $res;
	}

	/**
	 * Refresh a single projects changelog in the local cache and return it.
	 *
	 * If there is any error while loading the changelog from the API the
	 * function will return boolean false and not update the cache.
	 *
	 * The changlog is cached in a transient for 7 days.
	 *
	 * @since  4.0.0
	 *
	 * @param int $pid Refresh changelog of this project-ID.
	 *
	 * @return array|bool
	 */
	public function refresh_changelog( int $pid ) {
		$res = false;

		/*
		Note: This endpoint does not require an API key.
		 */

		if ( defined( 'WP_INSTALLING' ) ) {
			return false;
		}

		// changelog technically isn't authed API. But _if_ we decided that disconnected sites doesn't need to show updates, then this call serve no purpose when api key not there ( disconnected ).
		if ( ! $this->has_key() ) {
			return false;
		}

		$response = WPMUDEV_Dashboard::$api->call( 'changelog/' . $pid );

		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) ) {
				$data['timestamp'] = time();
				WPMUDEV_Dashboard::$settings->set_transient(
					'changelog_' . $pid,
					$data,
					WEEK_IN_SECONDS
				);
				$res = $data;
			} else {
				$this->parse_api_error( 'Error unserializing remote response' );
			}
		} else {
			$this->parse_api_error( $response );
		}

		return $res;
	}

	/*
	 * *********************************************************************** *
	 * *     TRANSLATION UPDATE FUNCTIONS
	 * *********************************************************************** *
	 */

	/**
	 * Get translation details from the API.
	 * The API usually returns specific data of all projects
	 * so this is parsed and sorted here.
	 *
	 * @since  4.8.0
	 *
	 * @param string $locale Locale to search translations for.
	 * @param bool   $force  Forcing will update the data and ignore cache.
	 */
	public function get_project_locale_translations( string $locale, bool $force = false ) {
		$res = false;

		/*
		Note: This endpoint requires an API key.
		 */

		if ( defined( 'WP_INSTALLING' ) ) {
			return false;
		}

		// if no locale is present return.
		if ( ! $locale ) {
			return false;
		}

		// return from cache if possible. Get locale baset cache.
		$cached = WPMUDEV_Dashboard::$settings->get_transient( 'translations_all_' . $locale );
		// Return from cache.
		if ( false !== $cached && ! $force ) {
			return $cached;
		}

		// Get last check time.
		$last_checked = WPMUDEV_Dashboard::$settings->get( 'last_run_translation', 'general', 0 );
		// Already checked in within last 12 hours. Skip API call.
		if ( false !== $cached && ! empty( $last_checked ) && $last_checked > ( time() - DAY_IN_SECONDS ) ) {
			return $cached;
		}

		// Do not continue if no API is set.
		if ( ! $this->has_key() ) {
			return false;
		}

		// set api base.
		$api_base = $this->server_root . $this->rest_api_translation;

		// sets up special auth header.
		$options['headers']                  = array();
		$options['headers']['Authorization'] = $this->get_key();

		$response = WPMUDEV_Dashboard::$api->call(
			$api_base . 'sets/' . $locale . '/projects',
			false,
			'GET',
			$options
		);

		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) ) {
				$res = $data;
			} else {
				$this->parse_api_error( 'Error unserializing remote response' );
			}
		} else {
			$this->parse_api_error( $response );
		}

		if ( is_array( $res ) ) {
			$res = $this->sort_translation_projects( $res );
		}
		$data['timestamp'] = time();
		WPMUDEV_Dashboard::$settings->set_transient( 'translations_all_' . $locale, $res, WEEK_IN_SECONDS );
		// Set last checked time.
		WPMUDEV_Dashboard::$settings->set( 'last_run_translation', time(), 'general' );

		return $res;
	}

	/**
	 * Parses the Translation API response data and sort active premium projects
	 *
	 * @since  4.8.0
	 *
	 * @param array $translations Response data from Translation API call to parse.
	 */
	public function sort_translation_projects( array $translations ): array {
		$data        = WPMUDEV_Dashboard::$api->get_projects_data();
		$projects    = $data['projects'];
		$project_ids = array();
		if ( ! empty( $projects ) ) {
			$project_ids = wp_list_pluck( $projects, 'id' );
		}
		$project_translation = array();

		foreach ( $translations as $project ) {
			if ( is_wp_error( $project ) ) {
				continue;
			}
			if ( in_array( $project['dev_project_id'], $project_ids, true ) ) {
				$project_translation[] = $project;
			}
		}

		return $project_translation;
	}

	/**
	 * Calculate if the translation files need update.
	 *
	 * @since  4.8.0
	 *
	 * @param bool $force Whether to force calculation without cached data.
	 */
	public function calculate_translation_upgrades( bool $force = false ) {
		$available_translation = wp_get_installed_translations( 'plugins' );
		$projects              = array();
		$translation_needed    = array();
		$locale                = WPMUDEV_Dashboard::$settings->get( 'translation_locale', 'general' );
		$auto_update           = WPMUDEV_Dashboard::$settings->get( 'enable_auto_translation', 'flags' );
		$update_available      = WPMUDEV_Dashboard::$settings->get( 'translation_updates_available' );
		$translations          = $this->get_project_locale_translations( $locale, $force );

		// if no key provided, update won't be doable anyway.
		if ( ! WPMUDEV_Dashboard::$api->has_key() ) {
			return array();
		}

		// cache.
		if ( ! $force && false !== $update_available && is_array( $update_available ) ) {
			return $update_available;
		}

		if ( $translations ) {
			// sort installed plugins.
			foreach ( $translations as $value ) {
				$project = WPMUDEV_Dashboard::$site->get_project_info( $value['dev_project_id'] );
				if ( ! empty( $project->is_installed ?? null ) ) {
					/**
					 * Handle Snapshot translation slug.
					 * https://incsub.atlassian.net/browse/WDD-187
					 */
					$value['translation_slug'] = 3760011 === (int) $value['dev_project_id'] ? 'snapshot' : $value['slug'];
					$value['version']          = $project->version_installed;
					$value['name']             = $project->name;
					$projects[]                = $value;
				}
			}
		}

		// check if translation is not installed and if is installed check if is available.
		foreach ( $projects as $updates ) {
			if (
				! array_key_exists( $updates['translation_slug'], $available_translation )
				|| ! array_key_exists( $locale, $available_translation[ $updates['translation_slug'] ] )
				|| ( strtotime( $available_translation[ $updates['translation_slug'] ][ $locale ]['PO-Revision-Date'] ) < strtotime( $updates['sets'][0]['last_modified_utc'] ) )
			) {

				// package url.
				$package = $this->rest_url_auth( $updates['sets'][0]['download_url'] );
				$package = add_query_arg(
					array(
						'format' => 'pomo_zip',
					),
					$package
				);

				$translation_needed[] = array(
					'type'       => 'plugin',
					'slug'       => $updates['translation_slug'],
					'language'   => $locale,
					'version'    => $updates['version'],
					'updated'    => $updates['sets'][0]['last_modified_utc'],
					'package'    => $package,
					'autoupdate' => (bool) $auto_update,
					'name'       => $updates['name'],
				);
			}
		}

		WPMUDEV_Dashboard::$settings->set( 'translation_updates_available', $translation_needed );

		return $translation_needed;
	}

	/**
	 * Auto update the translation files.
	 *
	 * @since  4.8.0
	 */
	public function maybe_update_translations(): void {
		if ( WPMUDEV_Dashboard::$settings->get( 'enable_auto_translation', 'flags' ) ) {
			// upgrade all the translations.
			WPMUDEV_Dashboard::$upgrader->upgrade_translation();
		}
	}

	/**
	 * Compares the list of local plugins/themes against Api data to determine
	 * available updates. Save the details to wdp_un_updates_available site
	 * option for later use.
	 *
	 * @since  1.0.0
	 *
	 * @param array|false $local_projects List of local projects from the transient.
	 * @param int         $force_update   Optional. A single project ID that is marked
	 *                                    for update, regardless of the version-check.
	 *
	 * @return array
	 */
	public function calculate_upgrades( $local_projects = false, int $force_update = 0 ): array {
		$updates = array();

		// if no key provided, update won't be doable anyway.
		if ( ! WPMUDEV_Dashboard::$api->has_key() ) {
			return $updates;
		}

		if ( ! is_array( $local_projects ) ) {
			$local_projects = WPMUDEV_Dashboard::$site->get_cached_projects();
		}

		// Check for updates.
		foreach ( $local_projects as $pid => $dummy ) {
			// Skip if the project is not installed on current site.
			$item = WPMUDEV_Dashboard::$site->get_project_info( $pid );
			if ( ! $item || empty( $item->name ) ) {
				continue;
			}
			if ( ! $item->is_installed ) {
				continue;
			}

			if ( (int) $pid !== $force_update ) {
				if ( ! $item->has_update ) {
					continue;
				}

				/**
				 * Allows excluding certain projects from update notifications.
				 *
				 * Basically just check the ID and return true if you want to
				 * silence updates.
				 *
				 * Filter result is only used if the remote-project `autoupdate`
				 * attribute does not have value 2.
				 *
				 * @since  1.0.0
				 *
				 * @param bool $flag Defaults to false, return true to silence.
				 * @param int  $pid  The WDP ID of the plugin/theme
				 *
				 * @api    wpmudev_project_ignore_updates
				 */
				$silence = apply_filters(
					'wpmudev_project_ignore_updates',
					false,
					$pid
				);

				// Handle WP auto-upgrades.
				if ( $silence ) {
					continue;
				}
			}

			// Fallback image is main thumbnail.
			$icon = $item->url->thumbnail;
			if ( ! empty( $item->url->icon ) ) {
				// Use icon if available.
				$icon = $item->url->icon;
			} elseif ( ! empty( $item->url->thumbnail_square ) ) {
				// If icon not available, check if we can use square thumb.
				$icon = $item->url->thumbnail_square;
			}

			// Add to array.
			$updates[ $pid ] = array(
				'url'              => $item->url->website,
				'type'             => $item->type,
				'is_addon'         => $item->is_addon ?? false,
				'instructions_url' => $item->url->instructions,
				'name'             => $item->name,
				'filename'         => $item->filename,
				'thumbnail'        => $icon,
				'version'          => $item->version_installed,
				'new_version'      => $item->version_latest,
				'changelog'        => $item->changelog,
				'autoupdate'       => $item->can_autoupdate ? 1 : 0,
			);
		}

		// Record results.
		WPMUDEV_Dashboard::$settings->set( 'updates_available', $updates );

		return $updates;
	}

	/**
	 * Remove projects from the data array that are not available for the
	 * current users membership-plan.
	 *
	 * This means:
	 * - FULL members will NOT see any LITE projects.
	 *
	 * @since  4.0.0
	 *
	 * @param array $data Response from the API.
	 *
	 * @return array Modified response from the API.
	 */
	protected function strip_unavailable_projects( array $data ): array {
		if ( empty( $data['projects'] ) ) {
			return $data;
		}

		$my_level = $this->get_membership_status();

		foreach ( $data['projects'] as $id => $project ) {
			if ( 'full' === (string) $my_level ) {
				// Remove lite from the projects list.
				if ( 'lite' === (string) $project['paid'] ) {
					unset( $data['projects'][ $id ] );
				}
			}
		}

		return $data;
	}

	/*
	 * *********************************************************************** *
	 * *     REMOTE ACCESS FUNCTIONS
	 * *********************************************************************** *
	 */

	/**
	 * Returns details about the remote access permission.
	 *
	 * If no param is specified the function will return a list of all access
	 * details. If a valid param is specified, the function will return a single
	 * string/value of the detail, or false if the detail-name is invalid.
	 *
	 * Details:
	 *   enabled (bool)
	 *   granted (int/timestamp)
	 *   expires (int/timestamp)
	 *   user (int/user-ID)
	 *
	 * @since  4.0.0
	 *
	 * @param ?string $detail Optional. Specify the requested detail.
	 *
	 * @return object|scalar The requested detail or all details.
	 */
	public function remote_access_details( ?string $detail = null ) {
		static $remote_details = null;

		if ( null === $remote_details ) {
			$remote_details = array();

			$remote_details['enabled'] = false;
			$remote_details['expires'] = 0;
			$remote_details['granted'] = 0;
			$remote_details['user']    = 0;

			$option_val = WPMUDEV_Dashboard::$settings->get( 'remote_access' );

			if ( ! ( defined( 'WPMUDEV_DISABLE_REMOTE_ACCESS' ) && WPMUDEV_DISABLE_REMOTE_ACCESS ) ) {
				$access = true;
				if ( ! $option_val ) {
					$access = false;
				} elseif ( ! is_array( $option_val ) ) {
					$access = false;
				}

				if ( $access ) {
					if ( isset( $option_val['expire'] ) ) {
						$remote_details['expires'] = (int) $option_val['expire'];
					}
					if ( isset( $option_val['granted'] ) ) {
						$remote_details['granted'] = (int) $option_val['granted'];
					}
					if ( isset( $option_val['userid'] ) ) {
						$remote_details['user'] = (int) $option_val['userid'];
					}
				}

				if ( $remote_details['expires'] <= time() ) {
					$access = false;
				}

				$remote_details['enabled'] = $access;
			}
		}

		// Reset access details for security if remote access is disabled.
		if ( ( defined( 'WPMUDEV_DISABLE_REMOTE_ACCESS' ) && WPMUDEV_DISABLE_REMOTE_ACCESS ) || ! $remote_details['enabled'] ) {
			$remote_details['enabled'] = false;
			$remote_details['expires'] = 0;
			$remote_details['granted'] = 0;
			$remote_details['user']    = 0;
		}

		if ( empty( $detail ) ) {
			return (object) $remote_details;
		} elseif ( isset( $remote_details[ $detail ] ) ) {
			return $remote_details[ $detail ];
		} else {
			return false;
		}
	}

	/**
	 * Enable WPMUDEV staff remote access login.
	 *
	 * @since  1.0.0
	 *
	 * @param string $action  Optional. Can either be 'start' or 'extend'.
	 *                        start .. Will grant access for 5 days from now.
	 *                        extend .. Will grant access for additional 3 days to the current
	 *                        expiration date. This option only works if support access is
	 *                        granted already.
	 */
	public function enable_remote_access( string $action = 'start' ): bool {
		global $current_user;

		if ( ! current_user_can( 'edit_users' ) ) {
			return false;
		}

		if ( defined( 'WPMUDEV_DISABLE_REMOTE_ACCESS' ) && WPMUDEV_DISABLE_REMOTE_ACCESS ) {
			return false;
		}

		if ( ! $this->has_key() ) {
			return false;
		}

		$details   = $this->remote_access_details();
		$time_base = time();
		$span      = '+5 Days'; // By default grant 5 days from now.

		if ( ( $details->enabled ?? false ) && ( $details->expires ?? time() ) > $time_base && 'extend' === $action ) {
			// When extending add 3 days to previous expire date.
			$time_base = $details->expires ?? time();
			$span      = '+3 Days';
		}

		// We will always create a new access key, even if we only extend!
		$access_key = wp_generate_password( 64, true );
		$expiration = strtotime( $span, $time_base );

		$response = WPMUDEV_Dashboard::$api->call_auth(
			'grant-access',
			array(
				'domain'      => $this->network_site_url(),
				'auth_key'    => $access_key,
				'auth_expire' => $expiration,
				'auth_url'    => admin_url( 'admin-ajax.php?action=wdpunauth' ),
			),
			'POST',
			array(
				'use_transient' => false,
			),
		);

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || 'true' !== (string) wp_remote_retrieve_body( $response ) ) {
			$this->parse_api_error( $response );

			return false;
		}

		// Save the access details.
		$access = array(
			'userid'  => $current_user->ID,
			'key'     => $access_key,
			'expire'  => $expiration,
			'granted' => time(),
		);
		WPMUDEV_Dashboard::$settings->set( 'remote_access', $access );

		return true;
	}

	/**
	 * Removes access ability for support staff.
	 *
	 * @since 1.0.0
	 */
	public function revoke_remote_access(): bool {
		// Do this whether or not we can update the API.
		WPMUDEV_Dashboard::$settings->set( 'remote_access', '' );

		if ( ! $this->has_key() ) {
			return false;
		}

		$response = $this->call_auth(
			'revoke-access',
			array(
				'domain' => $this->network_site_url(),
			),
			'POST',
			array(
				'use_transient' => false,
			),
		);

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$this->parse_api_error( $response );

			return false;
		}

		return true;
	}

	/**
	 * Listener for WPMU DEV staff remote access login.
	 *
	 * @since    1.0.0
	 * @internal Ajax handler
	 */
	public function authenticate_remote_access() {
		if ( defined( 'WPMUDEV_DISABLE_REMOTE_ACCESS' ) && WPMUDEV_DISABLE_REMOTE_ACCESS ) {
			wp_die( 'Error: Remote access disabled in wp-config' );
		}

		$access = WPMUDEV_Dashboard::$settings->get( 'remote_access' );

		// @codingStandardsIgnoreStart: We have own validation, not using nonce!
		$_REQUEST = $_POST;
		// @codingStandardsIgnoreEnd

		// using internal token for nonce like verify.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$error = false;
		if ( ! $access ) {
			$error = 'no token';
		} elseif ( ! is_array( $access ) ) {
			$error = 'no token';
		} elseif ( empty( $_REQUEST['wdpunkey'] ?? '' ) ) {
			$error = 'invalid';
		} elseif ( ! hash_equals( wp_unslash( $_REQUEST['wdpunkey'] ), $access['key'] ) ) { // timing attack safe key comparison. No sanitize, to avoid unintended stripping.
			$error = 'invalid';
		} elseif ( (int) $access['expire'] <= time() ) {
			$error = 'expired';
		}

		if ( ! $error ) {
			/* Authentication was successful, log in our support user. */

			// Force 1 hour cookie timeout.
			add_filter( 'auth_cookie_expiration', array( $this, 'auth_cookie_expiration' ) );

			/**
			 * Filter access user_id to be used on remote_access.
			 *
			 * @since 4.11.29
			 *
			 * @param int   $user_id User ID to be used on remote_access.
			 * @param array $access  Remote access details.
			 */
			$access['userid'] = apply_filters( 'wpmudev_remote_access_set_current_user_id', $access['userid'], $access );

			wp_clear_auth_cookie();
			wp_set_auth_cookie( $access['userid'], false );
			wp_set_current_user( $access['userid'] );

			/**
			 * Do action after successful remote access login..
			 *
			 * @since 4.11.29
			 *
			 * @param int   $user_id User ID being used on remote_access.
			 * @param array $access  Remote access details..
			 */
			do_action( 'wpmudev_remote_access_set_current_user', $access['userid'], $access );

			$secure_cookie = 'https' === wp_parse_url( get_option( 'home' ), PHP_URL_SCHEME );
			setcookie( 'wpmudev_is_staff', '1', time() + 3600, defined( 'COOKIEPATH' ) ? COOKIEPATH : '', COOKIE_DOMAIN, $secure_cookie, true );

			// Record login info.
			$access['logins'][ time() ] = array(
				'name'  => sanitize_text_field( wp_unslash( $_REQUEST['staff'] ?? '' ) ),
				'image' => sanitize_text_field( wp_unslash( $_REQUEST['gravatar_hash'] ?? '' ) ),
			);

			WPMUDEV_Dashboard::$settings->set( 'remote_access', $access );

			// Send to dashboard.
			$url = WPMUDEV_Dashboard::$ui->page_urls->support_url . '#/support-access';
			wp_safe_redirect( $url );
			exit;
		} else {
			// There was an error. Display the error message.
			switch ( $error ) {
				case 'no token':
					wp_die( 'The admin did not enable remote access. Please ask the user to grant access.' );// not fall-through, but no break needed. wp_die is exit by default.
				case 'expired':
					wp_die( 'This access token has expired. Please ask the user to renew it.' ); // not fall-through, but no break needed. wp_die is exit by default.
				case 'invalid':
				default:
					wp_die( 'This is an invalid access token. Please ask the user to grant access.' );
			}
		}

		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Listener for SSO through the Hub - 1st step.
	 * This step will check if the Dashboard user is logged in and the SSO is enabled.
	 * If so, it will redirect to the auth endpoint in the Hub to try the first hmac verification.
	 *
	 * @since    4.7.3
	 *
	 * @param string $redirect        Where to redirect after a successful SSO.
	 * @param string $nonce           Nonce coming from the DEV site, to later check if user is logged in.
	 * @param string $jwttoken        JWT Token coming from the DEV site, to later check if user is logged in.
	 * @param string $dev_user_apikey User API Key coming from the DEV site, to later check if user is logged in.
	 * @param string $hubteam         Arbitrary team ID, to later check if user has valid access to specified Hub Team.
	 *
	 * @internal Ajax handler
	 */
	public function authenticate_sso_access_step1( string $redirect, string $nonce, string $jwttoken = '', string $dev_user_apikey = '', string $hubteam = '' ) {
		// If user is already logged in, let's bypass the whole auth process.
		if ( is_user_logged_in() ) {
			$redirect = urldecode( $redirect );
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( defined( 'WPMUDEV_DISABLE_SSO' ) && WPMUDEV_DISABLE_SSO ) {
			wp_die( 'Error: Single Signon is disabled in wp-config' );
		}

		if ( ! $this->has_key() ) {
			wp_die( 'Error: Single Signon is unavailable.' );
		}

		$access = WPMUDEV_Dashboard::$settings->get( 'enabled', 'sso' );
		$user   = $this->refresh_profile();

		/**
		 * Checking if user is logged in.
		 * This could have been checked by
		 * just checking if api key is present
		 * but an extra layer has been added here
		 * to check if the api key can actually
		 * fetch proper data
		 */
		$logged = ! empty( $user ) && $this->has_key();

		$error = false;
		if ( ! $access ) {
			$error = 'sso_disabled';
		} elseif ( ! $logged ) {
			$error = 'no_logged_in_dashboard_user';
		}

		if ( ! $error ) {
			/* SSO is enabled and Dashboard user is logged in. */

			$token = uniqid() . '-' . microtime( true );
			WPMUDEV_Dashboard::$settings->set( 'active_token', $token, 'sso' );

			// Create state session cookie.
			$api_key = $this->get_key();

			$pre_sso_state = uniqid( '', true );
			$secure_cookie = 'https' === wp_parse_url( get_option( 'home' ), PHP_URL_SCHEME );

			setcookie( 'wdp-pre-sso-state', $pre_sso_state, time() + 3600, defined( 'COOKIEPATH' ) ? COOKIEPATH : '', COOKIE_DOMAIN, $secure_cookie, true );

			$hashed_pre_sso_state = hash_hmac( 'sha256', $pre_sso_state, $api_key );
			// Build hmac for OAuth.
			$domain  = $this->network_site_url();
			$profile = $this->get_profile();

			$outgoing_hmac = hash_hmac( 'sha256', $token . $hashed_pre_sso_state . $redirect . $domain, $api_key );
			WPMUDEV_Dashboard::$settings->set( 'step1_hmac', $outgoing_hmac, 'sso' );

			$auth_endpoint = $this->rest_url( 'sso-hub' );
			$auth_params   = array(
				'domain'        => $domain,
				'hmac'          => $outgoing_hmac,
				'token'         => $token,
				'pre_sso_state' => $hashed_pre_sso_state,
				'redirect'      => $redirect,
				'_hubteam'      => $hubteam,
			);

			// Use user id if available.
			if ( isset( $profile['profile']['id'] ) ) {
				$auth_params['user_id'] = (int) $profile['profile']['id'];
			} else {
				// Fallback to email in case we are still on old cache.
				$auth_params['email'] = rawurlencode( $profile['profile']['user_name'] );
			}

			if ( $jwttoken ) {
				$auth_params['_jwttoken'] = $jwttoken;
			} elseif ( $dev_user_apikey ) {
				$auth_params['_apikey'] = $dev_user_apikey;
			} else {
				// always fallback to nonce as default auth.
				$auth_params['_wpnonce'] = $nonce;
			}

			$auth_endpoint = add_query_arg( $auth_params, $auth_endpoint );

			/**
			 * Open redirect avoidance.
			 */
			add_filter(
				'wp_safe_redirect_fallback',
				function () {
					return site_url(); // fallback to home.
				}
			);
			add_filter(
				'allowed_redirect_hosts',
				function ( $hosts ) {
					$hosts   = is_array( $hosts ) ? $hosts : array();
					$hosts[] = wp_parse_url( $this->server_root, PHP_URL_HOST ); // allow api server only.

					return $hosts;
				}
			);
			wp_safe_redirect( $auth_endpoint );
			exit;
		} else {
			// There was an error. Display the error message.
			switch ( $error ) {
				case 'sso_disabled':
					$redirect_upon_failure = add_query_arg(
						array(
							'wdp_sso_fail' => 'sso_disabled',
						),
						wp_login_url( urldecode( $redirect ) )
					);

					wp_safe_redirect( $redirect_upon_failure );
					exit;

				case 'no_logged_in_dashboard_user':
					$redirect_upon_failure = add_query_arg(
						array(
							'wdp_sso_fail' => 'no_logged_in_dashboard_user',
						),
						wp_login_url( urldecode( $redirect ) )
					);

					wp_safe_redirect( $redirect_upon_failure );
					exit;
				default:
					$redirect_upon_failure = add_query_arg(
						array(
							'wdp_sso_fail' => 'unkown_reasons',
						),
						wp_login_url( urldecode( $redirect ) )
					);

					wp_safe_redirect( $redirect_upon_failure );
					exit;
			}
		}
	}

	/**
	 * Listener for SSO through the Hub - 2nd step.
	 * This step will verify the hmac coming from the Hub.
	 * If the verification works, it should log in the user and redirect him.
	 *
	 * @since    4.7.3
	 * @since    4.11.29 Simplify parameter format into an array.
	 *
	 * @param array $sso_access_data {
	 *                               incoming_hmac: string, The hmac coming from the Hub.
	 *                               token: string, The one-time passcode to prevent replay attacks.
	 *                               pre_sso_state: string, The state value that has been saved in a session cookie, in the previous step.
	 *                               redirect: string, The URL that the user needs to be redirected to.
	 *                               dev_user_id: int, The WPMU DEV User ID coming from the Hub.
	 *                               dev_user_email: string, The WPMU DEV User email address coming from the Hub.
	 *                               } An array of SSO Access data.
	 *
	 * @internal Ajax handler
	 */
	public function authenticate_sso_access_step2( array $sso_access_data ) {
		if ( defined( 'WPMUDEV_DISABLE_SSO' ) && WPMUDEV_DISABLE_SSO ) {
			wp_die( 'Error: Single Signon is disabled in wp-config' );
		}

		if ( ! $this->has_key() ) {
			wp_die( 'Error: Single Signon is unavailable.' );
		}

		$access = WPMUDEV_Dashboard::$settings->get( 'enabled', 'sso' );
		if ( ! $access ) {
			wp_die( 'Error: Single Signon is disabled.' );
		}

		$incoming_hmac = $sso_access_data['incoming_hmac'] ?? '';
		$token         = $sso_access_data['token'] ?? '';
		$pre_sso_state = $sso_access_data['pre_sso_state'] ?? '';
		$redirect      = $sso_access_data['redirect'] ?? '';

		$api_key        = $this->get_key();
		$verifying_hmac = hash_hmac( 'sha256', $token . $pre_sso_state . $redirect, $api_key );
		$redirect       = urldecode( $redirect );
		$step1_hmac     = WPMUDEV_Dashboard::$settings->get( 'step1_hmac', 'sso', '' );

		if ( ! is_string( $step1_hmac ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $step1_hmac ) ) {
			wp_die( 'Invalid SSO authentication response.' );
		}

		if ( hash_equals( $step1_hmac, $incoming_hmac ) ) {
			wp_die( 'Invalid SSO authentication response.' );
		}

		$userid = WPMUDEV_Dashboard::$settings->get( 'userid', 'sso' );
		$user   = $this->refresh_profile();

		$is_valid = hash_equals( $incoming_hmac, $verifying_hmac );

		if ( $is_valid && ! empty( $user ) ) {
			list( , $token_timestamp ) = explode( '-', $token );

			$token_timestamp_float = floatval( $token_timestamp );

			// Check if the token has expired.
			$current_time = microtime( true );
			if ( number_format( floatval( $current_time ) - $token_timestamp_float, 2 ) > $this->get_sso_token_expiry_time() ) {
				wp_die( 'The SSO token has expired.' );
			}

			// Check if the session cookie of the state value exists in the user's browser.
			if ( isset( $_COOKIE['wdp-pre-sso-state'] ) ) {
				// Check that the state value is the same with what was passed through the endpoint.
				$hmac_state_value = hash_hmac( 'sha256', sanitize_text_field( wp_unslash( $_COOKIE['wdp-pre-sso-state'] ) ), $api_key );

				if ( hash_equals( $hmac_state_value, $pre_sso_state ) ) {

					// Check if the token has been used in the past, to prevent replay attacks.
					$previous_sso_token = WPMUDEV_Dashboard::$settings->get( 'previous_token', 'sso', 0 );
					if ( $token_timestamp_float > $previous_sso_token ) {
						WPMUDEV_Dashboard::$settings->set( 'previous_token', $token_timestamp_float, 'sso' );
					} else {
						wp_die( 'The SSO token has been used in the past.' );
					}

					// Finally, check if the passed token is the same that was saved in the first place.
					$active_sso_token = WPMUDEV_Dashboard::$settings->get( 'active_token', 'sso' );
					if ( $token !== $active_sso_token ) {
						wp_die( 'The SSO token could not be verified.' );
					} else {
						WPMUDEV_Dashboard::$settings->set( 'active_token', uniqid(), 'sso' );
						WPMUDEV_Dashboard::$settings->set( 'step1_hmac', '', 'sso' );
					}

					/**
					 * Filter access user_id to be used on SSO.
					 *
					 * @since 4.11.29
					 *
					 * @param int   $userid          User ID to be used on SSO.
					 * @param array $sso_access_data SSO access details.
					 */
					$userid = apply_filters( 'wpmudev_sso_set_current_user_id', $userid, $sso_access_data );

					// If everything checks out, log in the user.
					wp_clear_auth_cookie();
					wp_set_auth_cookie( $userid, false );
					wp_set_current_user( $userid );

					/**
					 * Do action after successful SSO login.
					 *
					 * @since 4.11.29
					 *
					 * @param int    $usser_id        User ID being used on remote_access.
					 * @param array  $sso_access_data SSO access details.
					 * @param string $redirect        Where to redirect after a successful SSO.
					 */
					do_action( 'wpmudev_sso_set_current_user', $userid, $sso_access_data, $redirect );

					wp_safe_redirect( $redirect );
					exit;
				} else {
					if ( defined( 'WPMUDEV_API_DEBUG' ) && WPMUDEV_API_DEBUG ) {
						error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							sprintf(
								'WPMU DEV Dashboard Error: SSO failed. Expected: %s / Recieved: %s',
								$hmac_state_value,
								$pre_sso_state
							)
						);
					}
					wp_die( 'Passed state value does not match with the session cookie.' );
				}
			} else {
				wp_die( 'Session cookie of the state value does not exist.' );
			}
		} else {
			wp_die( 'Key mismatch.' );
		}
	}

	/**
	 * Clear WPMUDEV hosting static cache if possible.
	 *
	 * Using Internal `wpmudev_hosting_purge_static_cache` Hosting function
	 *
	 * @return void
	 */
	public function maybe_clear_hosting_static_cache() {
		// Only if WPMUDEV hosting.
		if ( ! $this->is_wpmu_dev_hosting() ) {
			return;
		}

		if ( function_exists( 'wpmudev_hosting_purge_static_cache' ) ) {
			wpmudev_hosting_purge_static_cache();
		}
	}

	/**
	 * Enable and configure analytics to collect data for the site.
	 *
	 * @since  4.6
	 *
	 * @return bool
	 */
	public function analytics_enable(): bool {
		if ( ! $this->has_key() ) {
			return false;
		}
		$api_base = $this->server_root . $this->rest_api_analytics;

		$options = array( 'use_transient' => false );
		// sets up special auth header.
		$options['headers']                  = array();
		$options['headers']['Authorization'] = $this->get_key();

		$response = WPMUDEV_Dashboard::$api->call(
			$api_base . 'enable',
			array(
				'domain'       => $this->network_site_url(),
				'sync_version' => WPMUDEV_Dashboard::$version,
			),
			'POST',
			$options
		);
		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $data['site_id'], $data['tracker'] ) ) {
				WPMUDEV_Dashboard::$settings->set( 'site_id', $data['site_id'], 'analytics' );
				WPMUDEV_Dashboard::$settings->set( 'tracker', $data['tracker'], 'analytics' );
				if ( isset( $data['script_url'] ) ) {
					WPMUDEV_Dashboard::$settings->set( 'script_url', $data['script_url'], 'analytics' );
				}

				// Clear WPMUDEV static cache.
				$this->maybe_clear_hosting_static_cache();

				return true;
			}
		} else {
			$this->parse_api_error( $response );
		}

		return false;
	}

	/**
	 * Enable and configure analytics to collect data for the site.
	 *
	 * @since  4.6
	 *
	 * @return bool
	 */
	public function analytics_disable(): bool {
		if ( ! $this->has_key() ) {
			return false;
		}
		$api_base = $this->server_root . $this->rest_api_analytics;

		$options = array( 'use_transient' => false );
		// sets up special auth header.
		$options['headers']                  = array();
		$options['headers']['Authorization'] = $this->get_key();

		$response = WPMUDEV_Dashboard::$api->call(
			$api_base . 'disable',
			array( 'domain' => $this->network_site_url() ),
			'POST',
			$options
		);

		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			// Clear WPMUDEV static cache.
			$this->maybe_clear_hosting_static_cache();

			return true;
		} else {
			$this->parse_api_error( $response );
		}

		return false;
	}

	/**
	 * Get overall analytics data for the given site.
	 *  Cached in a transient for 24hrs.
	 *
	 * @since  4.6
	 *
	 * @param int $days_ago How many days in the past to look back.
	 * @param int $subsite  If filtering to a subsite pass the blog_id of it.
	 *
	 * @return bool|array
	 */
	public function analytics_stats_overall( int $days_ago = 7, int $subsite = 0 ) {
		if ( ! $this->has_key() ) {
			return false;
		}
		$site_id = WPMUDEV_Dashboard::$settings->get( 'site_id', 'analytics' );
		$tracker = WPMUDEV_Dashboard::$settings->get( 'tracker', 'analytics' );

		// Analytics site id is needed.
		if ( empty( $site_id ) ) {
			return false;
		}

		// figure out what widget view we want.
		if ( is_multisite() ) {
			if ( $subsite ) {
				$type = 'subsite';
			} else {
				$type = 'network';
			}
		} else {
			$type = 'normal';
		}

		$api_base    = $this->server_root . $this->rest_api_analytics;
		$remote_path = add_query_arg( 'days_ago', $days_ago, sprintf( '%1$s/site/%2$s/overall/%3$s', untrailingslashit( $api_base ), $site_id, $type ) );
		if ( $subsite ) {
			$remote_path = add_query_arg( 'subsite', $subsite, $remote_path );
		}

		// Add hub site ID.
		$remote_path = add_query_arg( 'domain', $this->network_site_url(), $remote_path );

		// version to update the logic completely ( typically on plugin update )
		// include tracker url in cache key, as id can be collided between tracker.
		$transient_key = 'analytics_data_v1_' . md5( $remote_path . $tracker );
		// Get from transient.
		$cached = WPMUDEV_Dashboard::$settings->get_transient( $transient_key );

		// return from cache if possible. We don't use *_site_transient() to avoid unnecessary autoloading. ( we use it behind the scene though ?).
		if ( false !== $cached ) {
			$cached = $this->analytics_overall_filter_metrics( $cached );

			// Temporary fix to make data format in autocomplete format.
			if ( ! empty( $cached['autocomplete'][0]['value'] ) && is_array( $cached['autocomplete'][0]['value'] ) ) {
				foreach ( $cached['autocomplete'] as $index => $item ) {
					$cached['autocomplete'][ $index ] = array(
						'label'  => $item['label'],
						'value'  => $item['label'],
						'filter' => $item['value']['filter'],
						'type'   => $item['value']['type'],
					);
				}
			}

			return $cached;
		}

		// sets up special auth header.
		$options['headers']                  = array();
		$options['headers']['Authorization'] = $this->get_key();

		$response = WPMUDEV_Dashboard::$api->call(
			$remote_path,
			false,
			'GET',
			$options
		);

		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
		} else {
			$this->parse_api_error( $response );

			return false;
		}

		// parse the data into a format best for our needs.
		$final_data                 = array();
		$final_data['autocomplete'] = array();
		$comparison_data            = $data['comparision_overall'] ?? array();
		// overall data for charts and totals.
		if ( isset( $data['overall'] ) ) {

			// available fields are a bit different when filtered to subsite.
			$to_process = array(
				'bounce_rate'      => array(
					'orig_key' => 'bounce_rate',
					'label'    => __( 'Bounce Rate', 'wpmudev' ),
					'callback' => 'analytics_format_pcnt',
				),
				'exit_rate'        => array(
					'orig_key' => 'exit_rate',
					'label'    => __( 'Exit Rate', 'wpmudev' ),
					'callback' => 'analytics_format_pcnt',
				),
				'visit_time'       => array(
					'orig_key' => 'avg_time_on_site',
					'label'    => __( 'Visit Time', 'wpmudev' ),
					'callback' => 'analytics_format_time',
				),
				'visits'           => array(
					'orig_key' => 'nb_visits',
					'label'    => __( 'Entrances', 'wpmudev' ),
					'callback' => 'analytics_format_num',
				),
				'unique_visits'    => array(
					'orig_key' => 'nb_uniq_visitors',
					'label'    => __( 'Unique Visits', 'wpmudev' ),
					'callback' => 'analytics_format_num',
				),
				'pageviews'        => array(
					'orig_key' => 'nb_pageviews',
					'label'    => __( 'Page Views', 'wpmudev' ),
					'callback' => 'analytics_format_num',
				),
				'unique_pageviews' => array(
					'orig_key' => 'nb_uniq_pageviews',
					'label'    => __( 'Unique Page Views', 'wpmudev' ),
					'callback' => 'analytics_format_num',
				),
			);
			if ( $subsite ) {
				unset( $to_process['visits'] );
				unset( $to_process['unique_visits'] );
				unset( $to_process['visit_time'] );
				$to_process['pageviews']        = array(
					'orig_key' => 'nb_hits',
					'label'    => __( 'Pageviews', 'wpmudev' ),
					'callback' => 'analytics_format_num',
				);
				$to_process['unique_pageviews'] = array(
					'orig_key' => 'nb_visits',
					'label'    => __( 'Unique Pageviews', 'wpmudev' ),
					'callback' => 'analytics_format_num',
				);
				$to_process['page_time']        = array(
					'orig_key' => 'avg_time_on_page',
					'label'    => __( 'Page Time', 'wpmudev' ),
					'callback' => 'analytics_format_time',
				);
			}

			// phpcs:disable WordPress.DateTime.RestrictedFunctions.date_date
			foreach ( $data['overall'] as $date => $day ) {
				if ( isset( $day[0] ) ) {
					$day = $day[0];
				}

				// this helps data appear on correct day in x axis.
				$timestamp = date( 'c', strtotime( '+1 day', strtotime( $date ) ) );
				foreach ( $to_process as $key => $process ) {
					$y_value = $day[ $process['orig_key'] ] ?? null;

					$final_data['overall']['chart'][ $key ]['label']  = $process['label'];
					$final_data['overall']['chart'][ $key ]['data'][] = array(
						't' => $timestamp,
						'y' => $y_value,
					);
				}
			}

			foreach ( $comparison_data as $date => $day ) {
				if ( isset( $day[0] ) ) {
					$day = $day[0];
				}
				// this helps data appear on correct day in x axis.
				$timestamp = date( 'c', strtotime( '+1 day', strtotime( $date ) ) );
				foreach ( $to_process as $key => $process ) {
					$y_value                          = $day[ $process['orig_key'] ] ?? null;
					$comparing_data[ $key ]['label']  = $process['label'];
					$comparing_data[ $key ]['data'][] = array(
						't' => $timestamp,
						'y' => $y_value,
					);
				}
			}

			// for totals, we only wants if any of the days ( keys ) has page views
			// note: 1 visits can have multiple page views.
			$data_count_with_page_views = 0;
			if ( isset( $final_data['overall']['chart']['pageviews']['data'] ) ) {
				foreach ( $final_data['overall']['chart']['pageviews']['data'] as $value ) {
					if ( isset( $value['y'] ) && $value['y'] > 0 ) {
						++$data_count_with_page_views;
					}
				}
			}
			$data_compare_count_with_page_views = 0;
			if ( isset( $comparing_data['pageviews']['data'] ) ) {
				foreach ( $comparing_data['pageviews']['data'] as $value ) {
					if ( isset( $value['y'] ) && $value['y'] > 0 ) {
						++$data_compare_count_with_page_views;
					}
				}
			}

			foreach ( $to_process as $key => $process ) {
				if ( isset( $final_data['overall']['chart'][ $key ] ) ) {
					$compare_data = array();
					if ( isset( $comparing_data[ $key ] ) ) {
						$compare_data = wp_list_pluck( $comparing_data[ $key ]['data'], 'y' );
					}

					$list = wp_list_pluck( $final_data['overall']['chart'][ $key ]['data'], 'y' );

					// for number we want total, others mean.
					if ( 'analytics_format_num' === $process['callback'] ) {
						$totals        = array_sum( $list );
						$compare_total = array_sum( $compare_data );
					} else {
						$avg         = 0;
						$compare_avg = 0;
						if ( $data_count_with_page_views > 0 ) {
							$avg = array_sum( $list ) / $data_count_with_page_views;
						}
						if ( $data_compare_count_with_page_views > 0 ) {
							$compare_avg = array_sum( $compare_data ) / $data_compare_count_with_page_views;
						}
						$totals        = $avg;
						$compare_total = $compare_avg;
					}

					if ( count( $list ) ) {

						// assume 1 if no data found.
						$start = $compare_total > 0 ? abs( $compare_total ) : 0;

						if ( 0 === $start && 0 === abs( $totals ) ) {
							$end = 0;
						} else {
							$end = $totals > 0 ? abs( $totals ) : 1;
						}

						// if no data found the current data is the increment.
						if ( $start <= 0 && $end <= 0 ) {
							$change = 0;
						} elseif ( $start <= 0 ) {
							$change = round( $end, 1 );
						} else {
							$change = round( ( ( $end - $start ) / $start * 100 ), 1 );
						}
					} else {
						$change = 0;
					}

					$final_data['overall']['totals'][ $key ] = array(
						'change'    => number_format_i18n( abs( $change ) ) . '%',
						'direction' => ( 0.0 === (float) $change ) ? 'none' : ( $change > 0 ? 'up' : 'down' ),
						'value'     => call_user_func( array( $this, $process['callback'] ), $totals ),
					);
				}
			}
		}
		// phpcs:enable WordPress.DateTime.RestrictedFunctions.date_date

		$to_process = array(
			'pageviews'        => array(
				'orig_key' => 'nb_hits',
				'callback' => 'analytics_format_num',
			),
			'unique_pageviews' => array(
				'orig_key' => 'nb_visits',
				'callback' => 'analytics_format_num',
			),
			'bounce_rate'      => array(
				'orig_key' => 'bounce_rate',
				'callback' => 'analytics_format_pcnt',
			),
			'visits'           => array(
				'orig_key' => 'entry_nb_visits',
				'callback' => 'analytics_format_num',
			),
			'exit_rate'        => array(
				'orig_key' => 'exit_rate',
				'callback' => 'analytics_format_pcnt',
			),
			'gen_time'         => array(
				'orig_key' => 'avg_page_load_time',
				'callback' => 'analytics_format_time',
			),
			'page_time'        => array(
				'orig_key' => 'avg_time_on_page',
				'callback' => 'analytics_format_time',
			),
		);

		// top pages & posts list.
		if ( isset( $data['pages'] ) ) {
			foreach ( $data['pages'] as $page ) {
				$new_page = array(
					'filter' => $page['url'] ?? '',
					'name'   => isset( $page['label'] ) ? trim( $page['label'] ) : '',
				);

				// get desired categories.
				foreach ( $to_process as $key => $process ) {
					if ( isset( $page[ $process['orig_key'] ] ) ) {
						$new_page[ $key ] = array(
							'value' => call_user_func( array( $this, $process['callback'] ), $page[ $process['orig_key'] ] ),
							'sort'  => $page[ $process['orig_key'] ],
						);
					}
				}
				$final_data['pages'][]        = $new_page;
				$final_data['autocomplete'][] = array(
					/* translators: %s: Page name. */
					'label'  => sprintf( __( 'Page: %s', 'wpmudev' ), $new_page['name'] ),
					/* translators: %s: Page name. */
					'value'  => sprintf( __( 'Page: %s', 'wpmudev' ), $new_page['name'] ),
					'type'   => 'page',
					'filter' => $new_page['filter'],
				);
			}
		}

		// sites list.
		if ( isset( $data['sites'] ) && is_multisite() ) {
			$blog_ids = array();
			foreach ( $data['sites'] as $site ) {
				$new_site = array(
					'filter' => rawurlencode( $site['label'] ),
				);

				// try to get the blog domain from blog_id.
				$blog_id = trim( $site['label'] );
				if ( $blog_id && is_numeric( $blog_id ) && absint( $blog_id ) && ! in_array( absint( $blog_id ), $blog_ids, true ) ) {
					$blog = get_blog_details( absint( $blog_id ), true );
					if ( $blog ) {
						$blog_ids[]       = absint( $blog_id ); // save to make sure we only see each blog once (first with most data) in case of tracking bugs.
						$new_site['name'] = untrailingslashit( $blog->domain . $blog->path ) . ' - ' . $blog->blogname;
					} else {
						continue;
					}
				} else {
					continue;
				}

				// get desired categories.
				foreach ( $to_process as $key => $process ) {
					if ( isset( $site[ $process['orig_key'] ] ) ) {
						$new_site[ $key ] = array(
							'value' => call_user_func( array( $this, $process['callback'] ), $site[ $process['orig_key'] ] ),
							'sort'  => $site[ $process['orig_key'] ],
						);
					}
				}
				$final_data['sites'][]        = $new_site;
				$final_data['autocomplete'][] = array(
					/* translators: %s: Site Name. */
					'label'  => sprintf( __( 'Site: %s', 'wpmudev' ), $new_site['name'] ),
					/* translators: %s: Site Name. */
					'value'  => sprintf( __( 'Site: %s', 'wpmudev' ), $new_site['name'] ),
					'type'   => 'subsite',
					'filter' => $new_site['filter'],
				);
			}
		}

		// authors list.
		if ( isset( $data['authors'] ) ) {
			// page_time key is different for custom dimension.
			$to_process['page_time'] = array(
				'orig_key' => 'avg_time_on_dimension',
				'callback' => 'analytics_format_time',
			);

			foreach ( $data['authors'] as $author ) {

				// attempt to decode author json object.
				$author_object = json_decode( trim( $author['label'] ) );
				if ( ! isset( $author_object->ID ) ) {
					continue;
				}

				$new_author = array();
				$user       = get_userdata( $author_object->ID );
				if ( $user ) {
					$new_author['name']     = $user->display_name;
					$new_author['gravatar'] = get_avatar_url( $author_object->ID, array( 'size' => 25 ) );
				} else {
					$new_author['name']     = $author_object->name;
					$new_author['gravatar'] = get_avatar_url( $author_object->avatar, array( 'size' => 25 ) );
				}

				$new_author['filter'] = rawurlencode( $author['label'] );

				// get desired categories.
				foreach ( $to_process as $key => $process ) {
					if ( isset( $author[ $process['orig_key'] ] ) ) {
						$new_author[ $key ] = array(
							'value' => call_user_func( array( $this, $process['callback'] ), $author[ $process['orig_key'] ] ),
							'sort'  => $author[ $process['orig_key'] ],
						);
					}
				}
				$final_data['authors'][]      = $new_author;
				$final_data['autocomplete'][] = array(
					/* translators: %s Author Name. */
					'label'  => sprintf( __( 'Author: %s', 'wpmudev' ), $new_author['name'] ),
					/* translators: %s Author Name. */
					'value'  => sprintf( __( 'Author: %s', 'wpmudev' ), $new_author['name'] ),
					'type'   => 'author',
					'filter' => $new_author['filter'],
				);
			}
		}

		// Cache for later.
		WPMUDEV_Dashboard::$settings->set_transient( $transient_key, $final_data, DAY_IN_SECONDS );

		return $this->analytics_overall_filter_metrics( $final_data );
	}

	/**
	 * Get analytics data for a specific dimension query for the given site.
	 *  Not cached due to the vast number of possible args.
	 *
	 * @since  4.6
	 *
	 * @param int    $days_ago How many days in the past to look back.
	 * @param string $type     Can be page|author|subsite.
	 * @param string $filter   Page, author, or blog_id to filter to.
	 *
	 * @return bool|array
	 */
	public function analytics_stats_single( int $days_ago, string $type, string $filter ) {
		if ( ! $this->has_key() ) {
			return false;
		}
		$site_id = WPMUDEV_Dashboard::$settings->get( 'site_id', 'analytics' );
		$metrics = WPMUDEV_Dashboard::$site->get_metrics_on_analytics();

		$api_base    = $this->server_root . $this->rest_api_analytics;
		$remote_path = add_query_arg(
			array(
				'filter'   => $filter,
				'days_ago' => $days_ago,
				'domain'   => $this->network_site_url(),
			),
			sprintf( '%1$s/site/%2$s/%3$s', untrailingslashit( $api_base ), $site_id, $type )
		);

		// sets up special auth header.
		$options['headers']                  = array();
		$options['headers']['Authorization'] = $this->get_key();

		$response = WPMUDEV_Dashboard::$api->call(
			$remote_path,
			false,
			'GET',
			$options
		);

		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
		} else {
			$this->parse_api_error( $response );

			return false;
		}

		// parse the data into a format best for our needs.
		$final_data      = array();
		$comparison_data = $data['comparisions'] ?? array();

		// available fields are a bit different when filtered to subsite.
		$to_process = array(
			'bounce_rate'      => array(
				'orig_key' => 'bounce_rate',
				'label'    => __( 'Bounce Rate', 'wpmudev' ),
				'callback' => 'analytics_format_pcnt',
			),
			'exit_rate'        => array(
				'orig_key' => 'exit_rate',
				'label'    => __( 'Exit Rate', 'wpmudev' ),
				'callback' => 'analytics_format_pcnt',
			),
			'visits'           => array(
				'orig_key' => 'entry_nb_visits',
				'label'    => __( 'Entrances', 'wpmudev' ),
				'callback' => 'analytics_format_num',
			),
			'page_time'        => array(
				'orig_key' => 'avg_time_on_page',
				'label'    => __( 'Page Time', 'wpmudev' ),
				'callback' => 'analytics_format_time',
			),
			'pageviews'        => array(
				'orig_key' => 'nb_hits',
				'label'    => __( 'Pageviews', 'wpmudev' ),
				'callback' => 'analytics_format_num',
			),
			'unique_pageviews' => array(
				'orig_key' => 'nb_visits',
				'label'    => __( 'Unique Pageviews', 'wpmudev' ),
				'callback' => 'analytics_format_num',
			),
		);

		// limit metrics.
		if ( ! in_array( 'pageviews', $metrics, true ) ) {
			unset( $to_process['pageviews'] );
		}
		if ( ! in_array( 'unique_pageviews', $metrics, true ) ) {
			unset( $to_process['unique_pageviews'] );
		}
		if ( ! in_array( 'page_time', $metrics, true ) ) {
			unset( $to_process['page_time'] );
		}
		if ( ! in_array( 'bounce_rate', $metrics, true ) ) {
			unset( $to_process['bounce_rate'] );
		}
		if ( ! in_array( 'exit_rate', $metrics, true ) ) {
			unset( $to_process['exit_rate'] );
		}
		if ( ! in_array( 'visits', $metrics, true ) ) {
			unset( $to_process['visits'] );
		}

		// key is different for authors.
		if ( 'author' === $type ) {
			if ( in_array( 'page_time', $metrics, true ) ) {
				$to_process['page_time']['orig_key'] = 'avg_time_on_dimension';
			}
		}

		foreach ( $data as $date => $day ) {
			// Do not convert non-date values.
			if ( 'comparisions' === $date ) {
				continue;
			}

			if ( isset( $day[0] ) ) {
				$day = $day[0];
			}

			// phpcs:disable WordPress.DateTime.RestrictedFunctions.date_date
			// this helps data appear on correct day in x axis.
			$timestamp = date( 'c', strtotime( '+1 day', strtotime( $date ) ) );
			foreach ( $to_process as $key => $process ) {
				$y_value                               = $day[ $process['orig_key'] ] ?? null;
				$final_data['chart'][ $key ]['label']  = $process['label'];
				$final_data['chart'][ $key ]['data'][] = array(
					't' => $timestamp,
					'y' => $y_value,
				);
			}
		}

		foreach ( $comparison_data as $date => $day ) {
			// Do not convert non-date values.
			if ( 'comparisions' === $date ) {
				continue;
			}

			if ( isset( $day[0] ) ) {
				$day = $day[0];
			}
			// this helps data appear on correct day in x axis.
			$timestamp = date( 'c', strtotime( '+1 day', strtotime( $date ) ) );
			foreach ( $to_process as $key => $process ) {
				$y_value                                   = $day[ $process['orig_key'] ] ?? null;
				$comparing_data['chart'][ $key ]['label']  = $process['label'];
				$comparing_data['chart'][ $key ]['data'][] = array(
					't' => $timestamp,
					'y' => $y_value,
				);
			}
		}
		// phpcs:disable WordPress.DateTime.RestrictedFunctions.date_date
		foreach ( $to_process as $key => $process ) {
			if ( isset( $final_data['chart'][ $key ] ) ) {
				$list         = array_filter( wp_list_pluck( $final_data['chart'][ $key ]['data'], 'y' ) );
				$compare_data = array();
				$compare_avg  = false;

				if ( isset( $comparing_data['chart'][ $key ] ) ) {
					$compare_data = wp_list_pluck( $comparing_data['chart'][ $key ]['data'], 'y' );
				}

				// for number we want total, others mean.
				if ( 'analytics_format_num' === $process['callback'] ) {
					$totals        = array_sum( $list );
					$compare_total = array_sum( $compare_data );
				} else {
					if ( count( $list ) ) {
						$avg = array_sum( $list ) / count( $list );
						if ( ! empty( $compare_data ) && count( $compare_data ) ) {
							$compare_avg = array_sum( $compare_data ) / count( $compare_data );
						}
					} else {
						$avg = false;
					}
					$totals        = $avg;
					$compare_total = $compare_avg;
				}

				if ( count( $list ) ) {

					// assume 1 if no data found.
					$start = $compare_total > 0 ? abs( $compare_total ) : 0;

					if ( 0 === $start && 0 === abs( $totals ) ) {
						$end = 0;
					} else {
						$end = $totals > 0 ? abs( $totals ) : 1;
					}

					// if no data found the current data is the increment.
					if ( $start <= 0 && $end <= 0 ) {
						$change = 0;
					} elseif ( $start <= 0 ) {
						$change = round( $end, 1 );
					} else {
						$change = round( ( ( $end - $start ) / $start * 100 ), 1 );
					}
				} else {
					$change = 0;
				}

				$final_data['totals'][ $key ] = array(
					'change'    => number_format_i18n( abs( $change ) ) . '%',
					'direction' => ( 0.0 === (float) $change ) ? 'none' : ( $change > 0 ? 'up' : 'down' ),
					'value'     => call_user_func( array( $this, $process['callback'] ), $totals ),
				);
			}
		}

		return $final_data;
	}

	/*
	 * *********************************************************************** *
	 * *     INTERNAL ACTION HANDLERS
	 * *********************************************************************** *
	 */

	/**
	 * Callback to format percentage for analytics widget.
	 *
	 * @since  4.6
	 *
	 * @param int|float $decimal Number to format.
	 *
	 * @return string
	 */
	public function analytics_format_pcnt( $decimal ): string {
		if ( false === $decimal ) {
			return '-';
		}

		return round( $decimal * 100, 2 ) . '%';
	}

	/**
	 * Callback to format time for analytics widget.
	 *
	 * @since  4.6
	 *
	 * @param int|float $seconds Seconds to format.
	 *
	 * @return string
	 */
	public function analytics_format_time( $seconds ): string {
		if ( false === $seconds ) {
			return '-';
		}
		if ( $seconds >= 60 ) {
			$mins = round( ( $seconds / 60 ), 2 );

			return sprintf(
			/* translators: %s minutes. */
				_n( '%s min', '%s mins', $seconds, 'wpmudev' ),
				$mins
			);
		} elseif ( $seconds >= 1 ) {
			$seconds = round( $seconds, 2 );

			return sprintf(
			/* translators: %s seconds. */
				_n( '%s sec', '%s secs', $seconds, 'wpmudev' ),
				$seconds
			);
		} else {
			$milliseconds = round( $seconds * 1000 );

			return sprintf(
			/* translators: %s miliseconds. */
				__( '%s ms', 'wpmudev' ),
				$milliseconds
			);
		}
	}

	/**
	 * Callback to format number for analytics widget.
	 *
	 * @since  4.6
	 *
	 * @param int|float $number Number to format.
	 *
	 * @return string
	 */
	public function analytics_format_num( $number ): string {
		return number_format_i18n( round( $number ) );
	}

	/**
	 * Filter overall analytics data
	 *
	 * @since 4.7
	 *
	 * @param array $data Analytics data.
	 *
	 * @return array
	 */
	private function analytics_overall_filter_metrics( array $data ): array {
		$metrics = WPMUDEV_Dashboard::$site->get_metrics_on_analytics();
		// filter metrics.
		if ( isset( $data['overall'] ) && is_array( $data['overall'] ) ) {
			if ( isset( $data['overall']['chart'] ) && is_array( $data['overall']['chart'] ) ) {
				// limit metrics.
				if ( ! in_array( 'pageviews', $metrics, true ) ) {
					unset( $data['overall']['chart']['pageviews'] );
				}
				if ( ! in_array( 'unique_pageviews', $metrics, true ) ) {
					unset( $data['overall']['chart']['unique_pageviews'] );
				}
				if ( ! in_array( 'page_time', $metrics, true ) ) {
					unset( $data['overall']['chart']['page_time'] );
					unset( $data['overall']['chart']['visit_time'] );
				}
				if ( ! in_array( 'bounce_rate', $metrics, true ) ) {
					unset( $data['overall']['chart']['bounce_rate'] );
				}
				if ( ! in_array( 'exit_rate', $metrics, true ) ) {
					unset( $data['overall']['chart']['exit_rate'] );
				}
				if ( ! in_array( 'visits', $metrics, true ) ) {
					unset( $data['overall']['chart']['visits'] );
				}
			}
			if ( isset( $data['overall']['totals'] ) && is_array( $data['overall']['totals'] ) ) {
				// limit metrics.
				if ( ! in_array( 'pageviews', $metrics, true ) ) {
					unset( $data['overall']['totals']['pageviews'] );
				}
				if ( ! in_array( 'unique_pageviews', $metrics, true ) ) {
					unset( $data['overall']['totals']['unique_pageviews'] );
				}
				if ( ! in_array( 'page_time', $metrics, true ) ) {
					unset( $data['overall']['totals']['page_time'] );
					unset( $data['overall']['totals']['visit_time'] );
				}
				if ( ! in_array( 'bounce_rate', $metrics, true ) ) {
					unset( $data['overall']['totals']['bounce_rate'] );
				}
				if ( ! in_array( 'exit_rate', $metrics, true ) ) {
					unset( $data['overall']['totals']['exit_rate'] );
				}
				if ( ! in_array( 'visits', $metrics, true ) ) {
					unset( $data['overall']['totals']['visits'] );
				}
			}
		}

		if ( isset( $data['pages'] ) && is_array( $data['pages'] ) ) {
			foreach ( $data['pages'] as $key => $page ) {
				// limit metrics.
				if ( ! in_array( 'pageviews', $metrics, true ) ) {
					unset( $data['pages'][ $key ]['pageviews'] );
				}
				if ( ! in_array( 'unique_pageviews', $metrics, true ) ) {
					unset( $data['pages'][ $key ]['unique_pageviews'] );
				}
				if ( ! in_array( 'page_time', $metrics, true ) ) {
					unset( $data['pages'][ $key ]['page_time'] );
					unset( $data['pages'][ $key ]['visit_time'] );
				}
				if ( ! in_array( 'bounce_rate', $metrics, true ) ) {
					unset( $data['pages'][ $key ]['bounce_rate'] );
				}
				if ( ! in_array( 'exit_rate', $metrics, true ) ) {
					unset( $data['pages'][ $key ]['exit_rate'] );
				}
				if ( ! in_array( 'visits', $metrics, true ) ) {
					unset( $data['pages'][ $key ]['visits'] );
				}
			}
		}

		if ( isset( $data['sites'] ) && is_array( $data['sites'] ) ) {
			foreach ( $data['sites'] as $key => $site ) {
				// limit metrics.
				if ( ! in_array( 'pageviews', $metrics, true ) ) {
					unset( $data['sites'][ $key ]['pageviews'] );
				}
				if ( ! in_array( 'unique_pageviews', $metrics, true ) ) {
					unset( $data['sites'][ $key ]['unique_pageviews'] );
				}
				if ( ! in_array( 'page_time', $metrics, true ) ) {
					unset( $data['sites'][ $key ]['page_time'] );
					unset( $data['sites'][ $key ]['visit_time'] );
				}
				if ( ! in_array( 'bounce_rate', $metrics, true ) ) {
					unset( $data['sites'][ $key ]['bounce_rate'] );
				}
				if ( ! in_array( 'exit_rate', $metrics, true ) ) {
					unset( $data['sites'][ $key ]['exit_rate'] );
				}
				if ( ! in_array( 'visits', $metrics, true ) ) {
					unset( $data['sites'][ $key ]['visits'] );
				}
			}
		}

		if ( isset( $data['authors'] ) && is_array( $data['authors'] ) ) {
			foreach ( $data['authors'] as $key => $author ) {
				// limit metrics.
				if ( ! in_array( 'pageviews', $metrics, true ) ) {
					unset( $data['authors'][ $key ]['pageviews'] );
				}
				if ( ! in_array( 'unique_pageviews', $metrics, true ) ) {
					unset( $data['authors'][ $key ]['unique_pageviews'] );
				}
				if ( ! in_array( 'page_time', $metrics, true ) ) {
					unset( $data['authors'][ $key ]['page_time'] );
					unset( $data['authors'][ $key ]['visit_time'] );
				}
				if ( ! in_array( 'bounce_rate', $metrics, true ) ) {
					unset( $data['authors'][ $key ]['bounce_rate'] );
				}
				if ( ! in_array( 'exit_rate', $metrics, true ) ) {
					unset( $data['authors'][ $key ]['exit_rate'] );
				}
				if ( ! in_array( 'visits', $metrics, true ) ) {
					unset( $data['authors'][ $key ]['visits'] );
				}
			}
		}

		return $data;
	}

	/**
	 * Used to filter auth cookie expiration.
	 *
	 * @since  4.5
	 *
	 * @return int $timeout
	 */
	public function auth_cookie_expiration(): int {
		return apply_filters( 'wpmudev_dashboard_api_remote_access_cookie_expiration', HOUR_IN_SECONDS );
	}

	/**
	 * Parses an HTTP response object (or other value) to determine an error
	 * reason. The error reason is added to the PHP error log.
	 *
	 * @since  4.0.0
	 *
	 * @param mixed $response String, WP_Error object, HTTP response array.
	 */
	protected function parse_api_error( $response ) {
		$error_code = wp_remote_retrieve_response_code( $response );
		if ( ! $error_code ) {
			$error_code = 500;
		}
		$this->api_error = '';

		$body = is_array( $response )
			? wp_remote_retrieve_body( $response )
			: false;

		if ( is_scalar( $response ) ) {
			$this->api_error = $response;
		} elseif ( is_wp_error( $response ) ) {
			$this->api_error = $response->get_error_message();
		} elseif ( is_array( $response ) && ! empty( $body ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) && ! empty( $data['message'] ) ) {
				$this->api_error = $data['message'];
			}
		}

		$url = '(unknown URL)';
		if ( is_array( $response ) && isset( $response['request_url'] ) ) {
			$url = $response['request_url'];
		}

		if ( empty( $this->api_error ) ) {
			$this->api_error = sprintf(
				'HTTP Error: %s "%s"',
				$error_code,
				wp_remote_retrieve_response_message( $response )
			);
		}

		// Collect back-trace information for the logfile.
		if ( defined( 'WPMUDEV_API_DEBUG' ) && WPMUDEV_API_DEBUG ) {
			$trace       = wp_debug_backtrace_summary( null, 0, false ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_wp_debug_backtrace_summary
			$caller_dump = "\n\t# " . implode( "\n\t# ", $trace );

			if ( is_array( $response ) && isset( $response['request_url'] ) ) {
				$caller_dump = "\n\tURL: " . $response['request_url'] . $caller_dump;
			}

			// Log the error to PHP error log.
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'[WPMUDEV API Error] %s | %s (%s [%s]) %s',
					WPMUDEV_Dashboard::$version,
					$this->api_error,
					$url,
					$error_code,
					$caller_dump
				)
			);
		}

		// If error was "invalid API key" then log out the user. (we don't call logout here to avoid infinite loop).
		if ( 401 === (int) $error_code && ! defined( 'WPMUDEV_APIKEY' ) && ! defined( 'WPMUDEV_OVERRIDE_LOGOUT' ) ) {
			WPMUDEV_Dashboard::$api->set_key( '' );
		}
	}

	/**
	 * Get SSO token expiry time. In Seconds.
	 *
	 * @since 5.0.1
	 *
	 * @return int|float
	 */
	private function get_sso_token_expiry_time() {
		/**
		 * Override SSO token expiry time.
		 * Increase it, if site or network is slow.
		 *
		 * @since  5.0.1
		 *
		 * @param int|float $sso_token_expiry_time SSO token expiry time. In Seconds.
		 */
		return apply_filters( 'wpmudev_sso_token_expiry_time', self::SSO_TOKEN_EXPIRY_TIME );
	}
}