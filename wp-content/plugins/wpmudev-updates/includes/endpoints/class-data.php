<?php
/**
 * Data functionality REST endpoint.
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
 * Class Data
 */
class Data extends Endpoint {

	/**
	 * API endpoint for the current endpoint.
	 *
	 * @since 5.0.0
	 * @var string $endpoint
	 */
	private string $endpoint = '/data';

	/**
	 * Register the routes for handling data functionality.
	 *
	 * @since 5.0.0
	 *
	 * @return void
	 */
	public function register_routes() {
		// Routes to get user list.
		register_rest_route(
			$this->get_namespace(),
			$this->endpoint . '/available-users',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_users' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		// Routes to get site list.
		register_rest_route(
			$this->get_namespace(),
			$this->endpoint . '/sites',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_sites' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'search'   => array(
							'required'    => false,
							'description' => __( 'Search term.', 'wpmudev' ),
							'type'        => 'string',
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

		// Routes to get site list.
		register_rest_route(
			$this->get_namespace(),
			$this->endpoint . '/sites/details',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_sites_details' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'ids' => array(
							'required'    => true,
							'description' => __( 'ids of sites', 'wpmudev' ),
							'type'        => 'string',
						),
					),
				),
			)
		);

		// Routes to get service list.
		register_rest_route(
			$this->get_namespace(),
			$this->endpoint . '/services',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_services' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		// Routes to get system info.
		register_rest_route(
			$this->get_namespace(),
			$this->endpoint . '/system-info',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_system_info' ),
					'permission_callback' => array( $this, 'permissions_check_skip_allowed_users' ), // system info available on non logged in.
				),
			)
		);
	}

	/**
	 * Get list of available users.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_users( $request ) {
		$users = array();
		// Get available users.
		$available_users = WPMUDEV_Dashboard::$site->get_available_users( 0, false );
		$allowed         = WPMUDEV_Dashboard::$site->get_allowed_users( true );

		foreach ( $available_users as $user ) {
			$user->ID = (int) $user->ID;
			if ( isset( $user->id ) ) {
				$user->id = $user->ID;
			}
			// Add avatar URL.
			$user->avatar_url = get_avatar_url( $user->ID );
			$user->is_allowed = in_array( $user->ID, array_map( 'intval', $allowed ), true );
			$users[]          = $user;
		}

		return $this->get_response( $users );
	}

	/**
	 * Get list of sites using search query.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_sites( $request ) {
		$search   = $request->get_param( 'search' );
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );

		if ( ! is_multisite() ) {
			return $this->get_error_response( 'not_multisite', __( 'Not a multisite to search sites.', 'wpmudev' ) );
		}

		$items = array();

		$args = array(
			'search'        => sanitize_text_field( $search ),
			'fields'        => 'ids',
			'number'        => $per_page ?? 10,
			'offset'        => ( $page - 1 ) * $per_page,
			'no_found_rows' => false,
		);

		// Get site ids.
		$query    = new \WP_Site_Query();
		$site_ids = $query->query( $args );
		$total    = $query->found_sites;

		if ( ! empty( $site_ids ) ) {
			foreach ( $site_ids as $site_id ) {
				$items[] = array(
					'id'   => $site_id,
					'text' => str_replace( array( 'https://', 'http://' ), '', get_home_url( $site_id ) ),
				);
			}
		}

		return $this->get_response_with_pagination( $per_page, $total, $items );
	}

	/**
	 * Get sites details given its id
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_sites_details( $request ) {
		$ids = $request->get_param( 'ids' );

		$site_ids = explode( ',', $ids );
		$site_ids = array_filter( $site_ids );// remove empty.

		if ( ! is_multisite() ) {
			return $this->get_error_response( 'not_multisite', __( 'Not a multisite.', 'wpmudev' ) );
		}

		$items = array();

		foreach ( $site_ids as $site_id ) {
			$text = str_replace( array( 'https://', 'http://' ), '', get_home_url( $site_id ) );

			// Add to the array only if the text is not empty.
			if ( ! empty( $text ) ) {
				$items[] = array(
					'id'   => $site_id,
					'text' => $text,
				);
			}
		}

		return $this->get_response( $items );
	}

	/**
	 * Get list of services.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_services( $request ) {
		$urls       = WPMUDEV_Dashboard::$ui->page_urls;
		$membership = WPMUDEV_Dashboard::$settings->get( 'membership_data' );
		$site_id    = WPMUDEV_Dashboard::$api->get_site_id();
		$url_base   = sprintf( '%s/site/%s', $urls->hub_url, $site_id );

		$services = array(
			array(
				'title'       => __( 'Uptime', 'wpmudev' ),
				'url'         => esc_url( sprintf( '%s/uptime', $url_base ) ),
				'icon'        => 'hummingbird',
				'is_activate' => ! empty( $membership['services']['uptime'] ),
			),
			array(
				'title'       => __( 'Automate', 'wpmudev' ),
				'url'         => esc_url( sprintf( '%s/pluginsThemes/automate', $url_base ) ),
				'icon'        => 'defender',
				'is_activate' => ! empty( $membership['services']['automate'] ),
			),
			array(
				'title'       => __( 'Reports', 'wpmudev' ),
				'url'         => esc_url( sprintf( '%s/reports', $url_base ) ),
				'icon'        => 'smart-crawl',
				'is_activate' => ! empty( $membership['services']['reports'] ),
			),
		);

		return $this->get_response( $services );
	}

	/**
	 * Get the system info data.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_system_info( $request ) {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' ); // to trigger WP_MAX_MEMORY_LIMIT ( which isn't triggered in WP REST ).
		}
		$info = array(
			'php'    => $this->get_php_vars(),
			'mysql'  => $this->get_mysql_vars(),
			'server' => $this->get_server_vars(),
			'wp'     => $this->get_wp_vars(),
			'http'   => $this->get_http_vars(),
		);

		return $this->get_response( $info );
	}

	/**
	 * Get the list of PHP vars.
	 *
	 * @since 5.0.0
	 *
	 * @return array
	 */
	private function get_php_vars() {
		return WPMUDEV_Dashboard::$ui->get_php_vars();
	}

	/**
	 * Get list of MySQL vars.
	 *
	 * @since 5.0.0
	 *
	 * @return array
	 */
	private function get_mysql_vars() {
		global $wpdb;

		$vars = array();

		$mysql_vars = array(
			'key_buffer_size'    => true,   // Key cache size limit.
			'max_allowed_packet' => false,  // Individual query size limit.
			'max_connections'    => false,  // Max number of client connections.
			'query_cache_limit'  => true,   // Individual query cache size limit.
			'query_cache_size'   => true,   // Total cache size limit.
			'query_cache_type'   => 'ON',   // Query cache on or off.
		);
		$extra_info = array();

		// requires real time data + raw direct call.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$variables = $wpdb->get_results( "SHOW VARIABLES WHERE Variable_name IN ( '" . implode( "', '", array_keys( $mysql_vars ) ) . "' )" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		$dbh = $wpdb->dbh;
		if ( is_object( $dbh ) ) {
			$driver = get_class( $dbh );
			if ( method_exists( $dbh, 'db_version' ) ) {
				$version = $dbh->db_version();
			} elseif ( isset( $dbh->server_info ) ) {
				$version = $dbh->server_info;
			} elseif ( isset( $dbh->server_version ) ) {
				$version = $dbh->server_version;
			} else {
				$version = __( 'Unknown', 'wpmudev' );
			}
			if ( isset( $dbh->client_info ) ) {
				$extra_info['Driver version'] = $dbh->client_info;
			}
			if ( isset( $dbh->host_info ) ) {
				$extra_info['Connection info'] = $dbh->host_info;
			}
		} else {
			$version = __( 'Unknown', 'wpmudev' );
			$driver  = __( 'Unknown', 'wpmudev' );
		}
		$extra_info['Database']     = $wpdb->dbname;
		$extra_info['Charset']      = $wpdb->charset;
		$extra_info['Collate']      = $wpdb->collate;
		$extra_info['Table Prefix'] = $wpdb->prefix;

		$vars['Server Version'] = $version;
		$vars['Driver']         = $driver;
		foreach ( $extra_info as $key => $val ) {
			$vars[ $key ] = $val;
		}
		foreach ( $mysql_vars as $key => $val ) {
			$vars[ $key ] = $val;
		}
		foreach ( $variables as $item ) {
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$vars[ $item->Variable_name ] = $this->value_format( $item->Value );
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		return $vars;
	}

	/**
	 * Get list of WordPress vars.
	 *
	 * @since 5.0.0
	 *
	 * @return array
	 */
	private function get_wp_vars() {
		global $wp_version;

		$vars = array();

		$wp_constants = array(
			'ABSPATH',
			'WP_CONTENT_DIR',
			'WP_PLUGIN_DIR',
			'WPINC',
			'WP_LANG_DIR',
			'UPLOADBLOGSDIR',
			'UPLOADS',
			'WP_TEMP_DIR',
			'SUNRISE',
			'WP_ALLOW_MULTISITE',
			'MULTISITE',
			'SUBDOMAIN_INSTALL',
			'DOMAIN_CURRENT_SITE',
			'PATH_CURRENT_SITE',
			'SITE_ID_CURRENT_SITE',
			'BLOGID_CURRENT_SITE',
			'BLOG_ID_CURRENT_SITE',
			'COOKIE_DOMAIN',
			'COOKIEPATH',
			'SITECOOKIEPATH',
			'DISABLE_WP_CRON',
			'ALTERNATE_WP_CRON',
			'DISALLOW_FILE_MODS',
			'WP_HTTP_BLOCK_EXTERNAL',
			'WP_ACCESSIBLE_HOSTS',
			'WP_DEBUG',
			'WP_DEBUG_LOG',
			'WP_DEBUG_DISPLAY',
			'ERRORLOGFILE',
			'SCRIPT_DEBUG',
			'WP_LANG',
			'WP_MAX_MEMORY_LIMIT',
			'WP_MEMORY_LIMIT',
			'WPMU_ACCEL_REDIRECT',
			'WPMU_SENDFILE',
		);

		$vars['WordPress Version'] = $wp_version;

		foreach ( $wp_constants as $constant ) {
			$vars[ $constant ] = $this->const_format( $constant );
		}

		return $vars;
	}

	/**
	 * Get list of server vars.
	 *
	 * @since 5.0.0
	 *
	 * @return array
	 */
	private function get_server_vars() {
		$vars = array();

		// not user input.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$server = explode( ' ', $_SERVER['SERVER_SOFTWARE'] ?? '' );
		$server = explode( '/', reset( $server ) );

		$vars['Software Name']    = $server[0] ?? '';
		$vars['Software Version'] = $server[1] ?? 'Unknown';
		$vars['Server IP']        = $_SERVER['SERVER_ADDR'] ?? '';
		$vars['External IP']      = $_SERVER['SERVER_ADDR'] ?? '';
		$vars['Server Hostname']  = $_SERVER['SERVER_NAME'] ?? '';
		$vars['Server Admin']     = $_SERVER['SERVER_ADMIN'] ?? '';
		// phpcs:disable WordPress.DateTime.RestrictedFunctions.date_date
		$vars['Server local time'] = date( 'Y-m-d H:i:s (\U\T\C P)' );
		// phpcs:enable WordPress.DateTime.RestrictedFunctions.date_date
		$vars['Operating System'] = function_exists( 'php_uname' ) ? php_uname( 's' ) : '';
		$vars['OS Hostname']      = function_exists( 'php_uname' ) ? php_uname( 'n' ) : '';
		$vars['OS Version']       = function_exists( 'php_uname' ) ? php_uname( 'v' ) : '';

		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		return $vars;
	}

	/**
	 * Get list of HTTP vars.
	 *
	 * @since 5.0.0
	 *
	 * @return array
	 */
	private function get_http_vars() {
		$vars    = array();
		$options = array();
		if ( defined( 'WPMUDEV_API_UNCOMPRESSED' ) && \WPMUDEV_API_UNCOMPRESSED ) {
			$options['decompress'] = false;
		}

		$remote_url = WPMUDEV_Dashboard::$api->get_test_url();
		$url        = wp_parse_url( $remote_url );
		$remote_get = apply_filters( 'wpmudev_api_call_response', null, 'test', array(), 'GET', $options );
		if ( is_null( $remote_get ) ) {
			$remote_get = wp_remote_get( $remote_url, $options );
		}
		$remote_post = apply_filters( 'wpmudev_api_call_response', null, 'test', array(), 'POST', $options );
		if ( is_null( $remote_post ) ) {
			$remote_post = wp_remote_post( $remote_url, $options );
		}

		$remote_ip = apply_filters( 'wpmudev_http_remote_ip_api_call_response', null, $options );
		if ( is_null( $remote_ip ) ) {
			$remote_ip = wp_remote_get( 'https://ipinfo.io/ip', $options );
		}

		if ( is_wp_error( $remote_get ) ) {
			$remote_get = $remote_get->get_error_message();
		} else {
			$remote_get = wp_remote_retrieve_response_message( $remote_get );
		}

		if ( is_wp_error( $remote_post ) ) {
			$remote_post = $remote_post->get_error_message();
		} else {
			$remote_post = wp_remote_retrieve_response_message( $remote_post );
		}

		if ( is_wp_error( $remote_ip ) ) {
			$remote_ip = $remote_ip->get_error_message();
		} else {
			$remote_ip = wp_remote_retrieve_body( $remote_ip );
		}

		$vars['WPMU DEV API Server'] = $url['scheme'] . '://' . $url['host'];
		$vars['WPMU DEV: GET']       = $remote_get;
		$vars['WPMU DEV: POST']      = $remote_post;
		$vars['External IP']         = $remote_ip;

		return $vars;
	}

	/**
	 * Helper function for value formatting.
	 *
	 * @since 5.0.0
	 *
	 * @param mixed $val Value to format.
	 *
	 * @return string
	 */
	private function value_format( $val ) {
		if ( is_numeric( $val ) && ( $val >= ( 1024 * 1024 ) ) ) {
			$val = size_format( $val );
		}

		return $val;
	}

	/**
	 * Helper function.
	 *
	 * @since  4.0.0
	 *
	 * @param string $constant Name of a PHP const.
	 *
	 * @return string
	 */
	private function const_format( $constant ) {
		if ( ! defined( $constant ) ) {
			return '<em>undefined</em>';
		}

		$val = constant( $constant );
		if ( ! is_bool( $val ) ) {
			return $val;
		} elseif ( ! $val ) {
			return 'FALSE';
		} else {
			return 'TRUE';
		}
	}
}