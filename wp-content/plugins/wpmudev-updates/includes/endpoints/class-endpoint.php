<?php
/**
 * Abstract class for REST endpoint.
 *
 * @link    http://wpmudev.com
 * @since   4.12.0
 * @author  Joel James <joel@incsub.com>
 * @package WPMUDEV\Dashboard
 */

namespace WPMUDEV\Dashboard\Endpoints;

// If this file is called directly, abort.
defined( 'WPINC' ) || die;

use WP_REST_Request;
use WP_REST_Response;
use WPMUDEV_Dashboard;

/**
 * Class Endpoint
 */
abstract class Endpoint {

	/**
	 * API endpoint version.
	 *
	 * @since 5.0.0
	 *
	 * @var int $version
	 */
	protected int $version = 1;

	/**
	 * API endpoint namespace.
	 *
	 * @since 5.0.0
	 *
	 * @var string $namespace
	 */
	private string $namespace = 'wpmudev-dashboard';

	/**
	 * Endpoint constructor.
	 *
	 * We need to register the routes here.
	 *
	 * @since 5.0.0
	 */
	public function __construct() {
		// If the single instance hasn't been set, set it now.
		$this->register_hooks();
	}

	/**
	 * Set up WordPress hooks and filters
	 *
	 * @since 5.0.0
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Get namespace of the endpoint.
	 *
	 * @since 5.0.0
	 *
	 * @return string
	 */
	public function get_namespace(): string {
		// Setup namespace for the endpoint.
		return $this->namespace . '/v' . $this->version;
	}

	/**
	 * Get current version of the endpoint.
	 *
	 * @since 5.0.0
	 *
	 * @return int
	 */
	public function get_version(): int {
		return $this->version;
	}

	/**
	 * Get formatted response for the current request.
	 *
	 * @since 5.0.0
	 *
	 * @param mixed $data    Response data.
	 * @param bool  $success Is request success.
	 *
	 * @return WP_REST_Response
	 */
	public function get_response( $data = array(), bool $success = true ): WP_REST_Response {
		// Response status.
		$status = $success ? 200 : 400;

		return new WP_REST_Response(
			array(
				'success' => $success,
				'data'    => $data,
			),
			$status
		);
	}

	/**
	 * Get formatted response with pagination.
	 *
	 * @since 5.0.0
	 *
	 * @param int   $per_page Per page.
	 * @param int   $total    Total items.
	 * @param mixed $data     Data.
	 * @param bool  $success  Is request success.
	 *
	 * @return WP_REST_Response
	 */
	public function get_response_with_pagination( int $per_page, int $total, $data = array(), bool $success = true ): WP_REST_Response {
		$response = $this->get_response( $data, $success );
		// No need of pagination for error.
		if ( ! $success ) {
			return $response;
		}

		$response->header( 'X-WP-Total', (int) $total );
		$max_pages = $per_page ? ceil( $total / $per_page ) : 1;
		$response->header( 'X-WP-TotalPages', (int) $max_pages );

		return $response;
	}

	/**
	 * Get formatted error response.
	 *
	 * @since 5.0.0
	 *
	 * @param string|int $code    Error code.
	 * @param string     $message Error message.
	 * @param array      $data    Response data.
	 *
	 * @return WP_REST_Response
	 */
	public function get_error_response( $code = 'error', string $message = '', array $data = array() ): WP_REST_Response {
		$response = $this->get_response( $data, false );

		$data = $response->get_data();
		// Set error data.
		$data['code']    = $code;
		$data['message'] = $message;

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Retrieves a parameter from the request.
	 *
	 * This is a wrapper function to get default value if the param
	 * is not found. Also with optional sanitization.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request       Request object.
	 * @param string          $key           Parameter name.
	 * @param mixed           $default_value Default value.
	 *
	 * @return mixed
	 */
	public function get_param( WP_REST_Request $request, string $key, $default_value = '' ) {
		// Get param.
		$value = $request->get_param( $key );

		// Default value if null.
		return ( null === $value ? $default_value : $value );
	}

	/**
	 * Basic permission check for endpoints.
	 *
	 * @since 5.0.0
	 *
	 * @return bool
	 */
	public function permissions_check(): bool {
		if ( ! $this->permissions_check_skip_allowed_users() ) {
			return false;
		}

		if ( ! WPMUDEV_Dashboard::$site->allowed_user() ) {
			return false;
		}

		return true;
	}

	/**
	 * Permissions check but skip allowed users check.
	 *
	 * @since 5.0.0
	 * @return bool
	 */
	public function permissions_check_skip_allowed_users(): bool {
		$cap = is_multisite() ? 'manage_network_options' : 'manage_options';

		return current_user_can( $cap );
	}

	/**
	 * Register the routes for the objects of the controller.
	 *
	 * This should be defined in extending class.
	 *
	 * @since 5.0.0
	 */
	abstract public function register_routes();
}