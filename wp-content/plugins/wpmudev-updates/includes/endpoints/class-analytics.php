<?php
/**
 * Analytics functionality REST endpoint.
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
 * Class Analytics
 */
class Analytics extends Endpoint {

	/**
	 * API endpoint for the current endpoint.
	 *
	 * @since 5.0.0
	 * @var string $endpoint
	 */
	private string $endpoint = '/analytics';

	/**
	 * Register the routes for handling analytics functionality.
	 *
	 * @since 5.0.0
	 *
	 * @return void
	 */
	public function register_routes() {
		// Routes to manage the settings.
		register_rest_route(
			$this->get_namespace(),
			$this->endpoint,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_analytics' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'period'       => array(
							'required'    => true,
							'description' => __( 'Period to get analytics for.', 'wpmudev' ),
							'type'        => 'integer',
							'enum'        => array( 1, 7, 30, 90 ),
						),
						'type'         => array(
							'required'    => false,
							'description' => __( 'Analytics type (full or filtered).', 'wpmudev' ),
							'type'        => 'string',
							'default'     => 'full',
							'enum'        => array( 'filtered', 'full' ),
						),
						'filter_type'  => array(
							'required'    => false,
							'description' => __( 'Filter type.', 'wpmudev' ),
							'type'        => 'string',
							'enum'        => array( 'page', 'author', 'subsite' ),
						),
						'filter_value' => array(
							'required'    => false,
							'description' => __( 'Filter value.', 'wpmudev' ),
							'type'        => 'string',
						),
						'is_network'   => array(
							'required'    => false,
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Network-wide or single site.', 'wpmudev' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Check if the current user has permission to access analytics.
	 *
	 * Falback to Dash Plugin permissions check.
	 *
	 * @since 5.0.0
	 *
	 * @return bool
	 */
	public function permissions_check(): bool {
		if ( WPMUDEV_Dashboard::$site->user_can_analytics() ) {
			return true;
		}

		return parent::permissions_check();
	}

	/**
	 * Get the analytics data.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_analytics( WP_REST_Request $request ): WP_REST_Response {
		$type = $request->get_param( 'type' );

		// Only if user has the access.
		if ( ! WPMUDEV_Dashboard::$site->user_can_analytics() ) {
			return $this->get_error_response( 'unauthorized', __( 'You are not allowed to view analytics', 'wpmudev' ) );
		}

		if ( ! WPMUDEV_Dashboard::$settings->get( 'enabled', 'analytics' ) ) {
			return $this->get_error_response( 'analytics_disabled', __( 'Analytics is disabled', 'wpmudev' ) );
		}

		$filtered = array();

		// Get analytics data.
		if ( $request->has_param( 'filter_type' ) && $request->has_param( 'filter_value' ) ) {
			$filtered = $this->get_filtered_analytics( $request );
		}

		// If filtered data.
		if ( 'filtered' === $type ) {
			$data = $filtered;
		} else {
			// Get full data.
			$data = $this->get_full_analytics( $request );
		}

		// Replace current data with filtered data.
		if ( 'full' === $type && ! empty( $filtered ) ) {
			$data['current_data'] = $filtered;
		}

		if ( empty( $data ) ) {
			return $this->get_error_response( 'api_error', __( 'There was an API error, please try again', 'wpmudev' ) );
		}

		return $this->get_response( $data );
	}

	/**
	 * Get filtered analytics data for the period.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return array
	 */
	private function get_filtered_analytics( WP_REST_Request $request ): array {
		$period       = $request->get_param( 'period' );
		$filter_type  = $request->get_param( 'filter_type' );
		$filter_value = $request->get_param( 'filter_value' );

		// Make sure only allowed periods.
		$period = in_array( $period, array( 1, 7, 30, 90 ), true ) ? $period : 7;

		$data = array();

		// Get filtered data.
		if ( ! empty( $filter_type ) && ! empty( $filter_value ) ) {
			$data = WPMUDEV_Dashboard::$api->analytics_stats_single( $period, $filter_type, $filter_value );
		}

		return empty( $data ) ? array() : $data;
	}

	/**
	 * Get full analytics data for a selected period.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return array
	 */
	private function get_full_analytics( WP_REST_Request $request ): array {
		$period     = $request->get_param( 'period' );
		$is_network = $request->get_param( 'is_network' );
		// Make sure only allowed periods.
		$period = in_array( $period, array( 1, 7, 30, 90 ), true ) ? $period : 7;
		// Blog id to filter.
		$blog_id = ( $is_network || ! is_multisite() ) ? 0 : get_current_blog_id();

		// Get the stats data.
		$data = WPMUDEV_Dashboard::$api->analytics_stats_overall( $period, $blog_id );

		// Set final data.
		return array(
			'overall_data' => $data['overall'] ?? array(),
			'current_data' => $data['overall'] ?? array(),
			'pages'        => $data['pages'] ?? array(),
			'authors'      => $data['authors'] ?? array(),
			'sites'        => $data['sites'] ?? array(),
			'autocomplete' => $data['autocomplete'] ?? array(),
		);
	}
}