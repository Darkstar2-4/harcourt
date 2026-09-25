<?php
/**
 * Support functionality REST endpoint.
 *
 * @link    http://wpmudev.com
 * @since   4.12.0
 * @author  Joel James <joel@incsub.com>
 * @package WPMUDEV\Dashboard\Endpoints
 */

namespace WPMUDEV\Dashboard\Endpoints;

// If this file is called directly, abort.
defined( 'WPINC' ) || die;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPMUDEV_Dashboard;

/**
 * Class Support
 */
class Support extends Endpoint {

	/**
	 * API endpoint for the current endpoint.
	 *
	 * @since 5.0.0
	 * @var string $endpoint
	 */
	private string $endpoint = '/support';

	/**
	 * Register the routes for handling support functionality.
	 *
	 * @since 5.0.0
	 *
	 * @return void
	 */
	public function register_routes() {
		// Routes to manage the support.
		register_rest_route(
			$this->get_namespace(),
			$this->endpoint . '/tickets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_tickets' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'status'   => array(
							'required'    => false,
							'description' => __( 'Status to filter.', 'wpmudev' ),
							'type'        => 'string',
							'default'     => 'all',
							'enum'        => array( 'all', 'open', 'resolved', 'feedback' ),
						),
						'page'     => array(
							'required'    => false,
							'description' => __( 'Page number.', 'wpmudev' ),
							'type'        => 'integer',
							'default'     => 1,
							'minimum'     => 1,
						),
						'per_page' => array(
							'required'    => false,
							'description' => __( 'No. of sites per page.', 'wpmudev' ),
							'type'        => 'integer',
							'default'     => 10,
							'minimum'     => 1,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->get_namespace(),
			$this->endpoint . '/support-access',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_support_access' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'grant_support_access' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'extend' => array(
							'required'    => false,
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Extend existing support access.', 'wpmudev' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'revert_support_access' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->get_namespace(),
			$this->endpoint . '/support-access/notes',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_support_access_notes' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'notes' => array(
							'required'    => true,
							'type'        => 'string',
							'description' => __( 'Support access notes.', 'wpmudev' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->get_namespace(),
			$this->endpoint . '/support-access/sessions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_support_access_sessions' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Get list of tickets.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_tickets( WP_REST_Request $request ) {
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );
		$status   = $request->get_param( 'status' );

		$count   = 0;
		$threads = array();
		// Only if tickets are not hidden.
		if ( ! WPMUDEV_Dashboard::$api->is_tickets_hidden() ) {
			$profile = WPMUDEV_Dashboard::$api->get_profile();
			// Get threads.
			$threads = $profile['forum']['support_threads'] ?? array();

			// If filtered by status.
			if ( ! empty( $threads ) && 'all' !== $status ) {
				$threads = array_filter(
					$threads,
					function ( $thread_item ) use ( $status ) {
						// Title and status is required.
						if ( empty( $thread_item['title'] ) || empty( $thread_item['status'] ) ) {
							return false;
						}

						// Check for groups.
						if ( 'resolved' === $thread_item['status'] ) {
							return 'resolved' === $status;
						} elseif ( ! empty( $thread_item['unread'] ) ) {
							return 'feedback' === $status;
						} elseif ( 'open' === $status ) {
							return true;
						}

						return false;
					}
				);
			}

			$threads = array_values( $threads );

			// Get total count.
			$count = count( $threads );

			// For pagination.
			if ( $count && $count > $per_page ) {
				$offset  = ( $page - 1 ) * $per_page;
				$threads = array_slice( $threads, $offset, $per_page );
			}
		}

		return $this->get_response_with_pagination( $per_page, $count, $threads );
	}

	/**
	 * Grant support access.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_support_access( WP_REST_Request $request ) {
		$access_details = WPMUDEV_Dashboard::$api->remote_access_details();

		return $this->get_response( $access_details );
	}

	/**
	 * Get support access details.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function grant_support_access( WP_REST_Request $request ) {
		$extend = $request->get_param( 'extend' );
		// Support access action.
		$action = $extend ? 'extend' : 'start';

		$type                  = WPMUDEV_Dashboard::$api->get_membership_status();
		$is_wpmudev_host       = WPMUDEV_Dashboard::$api->is_wpmu_dev_hosting();
		$is_standalone_hosting = WPMUDEV_Dashboard::$api->is_standalone_hosting_plan();
		$has_hosted_access     = $is_wpmudev_host && ! $is_standalone_hosting && 'free' === $type;
		$has_support_access    = WPMUDEV_Dashboard::$api->is_support_allowed() || $has_hosted_access;

		if ( ! $has_support_access ) {
			return $this->get_error_response( 'no_support_access', __( 'You do not have support access.', 'wpmudev' ) );
		}

		// Enable support access.
		$success        = WPMUDEV_Dashboard::$api->enable_remote_access( $action );
		$access_details = array();
		// If success, get support access details.
		if ( $success ) {
			$access_details = WPMUDEV_Dashboard::$api->remote_access_details();
		}

		return $this->get_response( $access_details, $success );
	}

	/**
	 * Revert support access.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function revert_support_access( WP_REST_Request $request ) {
		$success = WPMUDEV_Dashboard::$api->revoke_remote_access();

		return $this->get_response( array(), $success );
	}

	/**
	 * Update support access notes.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function update_support_access_notes( WP_REST_Request $request ) {
		$notes = $request->get_param( 'notes' );

		// Update and get notes.
		$success = WPMUDEV_Dashboard::$settings->set( 'staff_notes', stripslashes( $notes ), 'general' );
		$notes   = WPMUDEV_Dashboard::$settings->get( 'staff_notes', 'general', '' );

		return $this->get_response( array( 'notes' => $notes ), $success );
	}

	/**
	 * Grant support access.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_support_access_sessions( WP_REST_Request $request ) {
		$access = WPMUDEV_Dashboard::$settings->get( 'remote_access' );
		// No sessions.
		if ( empty( $access['logins'] ) || ! is_array( $access['logins'] ) ) {
			return $this->get_response();
		}

		$sessions = array();
		// Format sessions.
		foreach ( $access['logins'] as $time => $user ) {
			$sessions[] = array(
				'time'  => WPMUDEV_Dashboard::$site->to_localtime( $time ),
				'name'  => $user['name'] ?? $user,
				'image' => isset( $user['image'] ) ? 'https://www.gravatar.com/avatar/' . $user['image'] : '',
			);
		}

		return $this->get_response( $sessions );
	}
}