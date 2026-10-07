<?php

/**
 * Gravity Forms Mailchimp API Library.
 *
 * @since     4.0
 * @package   GravityForms
 * @author    Rocketgenius
 * @copyright Copyright (c) 2016, Rocketgenius
 */
class GF_MailChimp_API {

	/**
	 * Mailchimp account API key.
	 *
	 * @since  4.0
	 * @access protected
	 * @var    string $api_key Mailchimp account API key.
	 */
	protected $api_key;

	/**
	 * Mailchimp account data center.
	 *
	 * @since  4.0
	 * @access protected
	 * @var    string $data_center Mailchimp account data center.
	 */
	protected $data_center;

	/**
	 * Initialize API library.
	 *
	 * @since 4.0
	 * @since 4.10 - Transitioned to oAuth2 Connections with Access Tokens and Server Prefixes.
	 *
	 * @access public
	 *
	 * @param string $access_token Mailchimp oAuth2 Access Token.
	 * @param string $server_prefix Mailchimp oAuth2 Server Prefix (Used as data center).
	 */
	public function __construct( $access_token, $server_prefix = '' ) {
		$this->api_key = $access_token;

		if ( ! empty( $server_prefix ) ) {
			$this->data_center = $server_prefix;
		}
	}

	/**
	 * Get current account details.
	 *
	 * @since  4.0
	 * @access public
	 *
	 * @uses   GF_MailChimp_API::process_request()
	 *
	 * @return array|WP_Error
	 */
	public function account_details() {

		return $this->process_request();

	}

	/**
	 * Delete a specific Mailchimp list/audience member.
	 *
	 * @since  4.6
	 * @access public
	 *
	 * @param string $list_id Mailchimp list/audience ID.
	 * @param string $email_address Email address.
	 *
	 * @uses   GF_MailChimp_API::process_request()
	 *
	 * @return array|WP_Error
	 */
	public function delete_list_member( $list_id, $email_address ) {

		// Prepare subscriber hash.
		$subscriber_hash = md5( strtolower( $email_address ) );

		return $this->process_request( 'lists/' . $list_id . '/members/' . $subscriber_hash, array(), 'DELETE' );

	}

	/**
	 * Get all interests for an interest category.
	 *
	 * @since  4.0
	 * @access public
	 *
	 * @param string $list_id Mailchimp list/audience ID.
	 * @param string $category_id Interest category ID.
	 *
	 * @uses   GF_MailChimp_API::process_request()
	 *
	 * @return array|WP_Error
	 */
	public function get_interest_category_interests( $list_id, $category_id ) {

		return $this->process_request( 'lists/' . $list_id . '/interest-categories/' . $category_id . '/interests', array( 'count' => 9999 ), 'GET', 'interests' );

	}

	/**
	 * Get a specific Mailchimp list/audience.
	 *
	 * @since  4.0
	 * @access public
	 *
	 * @param string $list_id Mailchimp list/audience ID.
	 *
	 * @uses   GF_MailChimp_API::process_request()
	 *
	 * @return array|WP_Error
	 */
	public function get_list( $list_id ) {

		return $this->process_request( 'lists/' . $list_id );

	}

	/**
	 * Get all Mailchimp lists.
	 *
	 * @since  4.0
	 * @access public
	 *
	 * @param array $params List/Audience request parameters.
	 *
	 * @uses   GF_MailChimp_API::process_request()
	 *
	 * @return array|WP_Error
	 */
	public function get_lists( $params ) {

		return $this->process_request( 'lists', $params );

	}

	/**
	 * Get all interest categories for a Mailchimp list/audience.
	 *
	 * @since  4.0
	 * @access public
	 *
	 * @param string $list_id Mailchimp list/audience ID.
	 *
	 * @uses   GF_MailChimp_API::process_request()
	 *
	 * @return array|WP_Error
	 */
	public function get_list_interest_categories( $list_id ) {

		return $this->process_request( 'lists/' . $list_id . '/interest-categories', array( 'count' => 9999 ), 'GET', 'categories' );

	}

	/**
	 * Get a specific Mailchimp list/audience member.
	 *
	 * @since  4.0
	 * @access public
	 *
	 * @param string $list_id Mailchimp list/audience ID.
	 * @param string $email_address Email address.
	 *
	 * @uses   GF_MailChimp_API::process_request()
	 *
	 * @return array|WP_Error
	 */
	public function get_list_member( $list_id, $email_address ) {

		// Prepare subscriber hash.
		$subscriber_hash = md5( strtolower( $email_address ) );

		return $this->process_request( 'lists/' . $list_id . '/members/' . $subscriber_hash );

	}

	/**
	 * Get Mailchimp list/audience members.
	 *
	 * @since  4.6
	 * @access public
	 *
	 * @param string $list_id Mailchimp list/audience ID.
	 * @param array $options Additional settings.
	 *
	 * @uses   GF_MailChimp_API::process_request()
	 *
	 * @return array|WP_Error
	 */
	public function get_list_members( $list_id, $options = array() ) {

		return $this->process_request( 'lists/' . $list_id . '/members', $options );

	}

	/**
	 * Get all merge fields for a Mailchimp list/audience.
	 *
	 * @since  4.0
	 * @access public
	 *
	 * @param string $list_id Mailchimp list/audience ID.
	 *
	 * @uses   GF_MailChimp_API::process_request()
	 *
	 * @return array|WP_Error
	 */
	public function get_list_merge_fields( $list_id ) {

		return $this->process_request( 'lists/' . $list_id . '/merge-fields', array( 'count' => 9999 ) );

	}

	/**
	 * Add or update a Mailchimp list/audience member.
	 *
	 * @since  4.0
	 * @since  5.2 - Add support for request method to allow PATCH requests.
	 * @access public
	 *
	 * @param string $list_id Mailchimp list/audience ID.
	 * @param string $email_address Email address.
	 * @param array $subscription Subscription details.
	 * @param string $method Request method. Defaults to PUT.
	 *
	 * @uses   GF_MailChimp_API::process_request()
	 *
	 * @return array|WP_Error
	 */
	public function update_list_member( $list_id, $email_address, $subscription, $method = 'PUT' ) {
		// Make sure that method is either PUT or PATCH.
		if ( ! in_array( $method, array( 'PUT', 'PATCH' ) ) ) {
			throw Exception( __METHOD__ . '(): Method must be one of PUT or PATCH.' );
		}

		$path = 'lists/' . $list_id . '/members/' . md5( strtolower( $email_address ) );

		if ( isset( $subscription['skip_merge_validation'] ) ) {
			if ( $subscription['skip_merge_validation'] ) {
				$path = add_query_arg( 'skip_merge_validation', 'true', $path );
			}
			unset( $subscription['skip_merge_validation'] );
		}

		return $this->process_request( $path, $subscription, $method );

	}

	/**
	 * Update tags for a Mailchimp list/audience member.
	 *
	 * @since  Unknown
	 * @access public
	 *
	 * @param string $list_id Mailchimp list/audience ID.
	 * @param string $email_address Email address.
	 * @param array $tags Member tags.
	 *
	 * @uses   GF_MailChimp_API::process_request()
	 *
	 * @return array|WP_Error
	 */
	public function update_member_tags( $list_id, $email_address, $tags ) {

		// Prepare subscriber hash.
		$subscriber_hash = md5( strtolower( $email_address ) );

		return $this->process_request( 'lists/' . $list_id . '/members/' . $subscriber_hash . '/tags', array( 'tags' => $tags ), 'POST' );

	}

	/**
	 * Add a note to the Mailchimp list/audience member.
	 *
	 * @since  4.0.10
	 * @access public
	 *
	 * @param string $list_id Mailchimp list/audience ID.
	 * @param string $email_address Email address.
	 * @param string $note The note to be added to the member.
	 *
	 * @uses   GF_MailChimp_API::process_request()
	 *
	 * @return array|WP_Error
	 */
	public function add_member_note( $list_id, $email_address, $note ) {

		// Prepare subscriber hash.
		$subscriber_hash = md5( strtolower( $email_address ) );

		return $this->process_request( 'lists/' . $list_id . '/members/' . $subscriber_hash . '/notes', array( 'note' => $note ), 'POST' );

	}

	/**
	 * Process Mailchimp API request.
	 *
	 * @since  4.0
	 * @since  5.7 Updated to return WP_Error instead of throwing an exception.
	 *
	 * @access private
	 *
	 * @param string $path Request path.
	 * @param array $data Request data.
	 * @param string $method Request method. Defaults to GET.
	 * @param string $return_key Array key from response to return. Defaults to null (return full response).
	 *
	 * @return array|WP_Error
	 */
	private function process_request( $path = '', $data = array(), $method = 'GET', $return_key = null ) {

		// Abort early if API key is not set.
		if ( rgblank( $this->api_key ) ) {
			return new WP_Error( 'empty_api_key', 'Access Token must be defined to process an API request.' );
		}

		// Build base request URL.
		$request_url = 'https://' . $this->get_data_center() . '.api.mailchimp.com/3.0/' . $path;

		// Add request URL parameters if needed.
		if ( 'GET' === $method && ! empty( $data ) ) {
			$request_url = add_query_arg( $data, $request_url );
		}

		$auth = 'Bearer ' . $this->api_key;

		// Deprecated API Key method detected - use that for auth to prevent breakage.
		if ( $this->get_data_center_from_api_key() ) {
			$auth = 'Basic ' . base64_encode( ':' . $this->api_key );
		}

		// Build base request arguments.
		$args = array(
			'method'    => $method,
			'headers'   => array(
				'Accept'        => 'application/json',
				'Authorization' => $auth,
				'Content-Type'  => 'application/json',
			),
			/**
			 * Filters if SSL verification should occur.
			 *
			 * @param bool false If the SSL certificate should be verified. Defalts to false.
			 *
			 * @return bool
			 */
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			/**
			 * Sets the HTTP timeout, in seconds, for the request.
			 *
			 * @param int    30           The timeout limit, in seconds. Defaults to 30.
			 * @param string $request_url The request URL.
			 *
			 * @return int
			 */
			'timeout'   => apply_filters( 'http_request_timeout', 30, $request_url ),
		);

		// Add data to arguments if needed.
		if ( 'GET' !== $method ) {
			$args['body'] = json_encode( $data );
		}

		/**
		 * Filters the Mailchimp request arguments.
		 *
		 * @param array $args The request arguments sent to Mailchimp.
		 * @param string $path The request path.
		 *
		 * @return array
		 */
		$args = apply_filters( 'gform_mailchimp_request_args', $args, $path );

		// Get request response.
		$response = wp_remote_request( $request_url, $args );

		// If request was not successful, throw exception.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Decode response body.
		$response['body'] = json_decode( $response['body'], true );

		// Get the response code.
		$response_code = wp_remote_retrieve_response_code( $response );

		if ( ! in_array( $response_code, array( 200, 204 ) ) ) {
			$message = rgar( $response['body'], 'detail' );
			if ( empty( $message ) ) {
				$message = wp_remote_retrieve_response_message( $response );
			} elseif ( ! empty( $response['body']['instance'] ) ) {
				$message .= ' Instance: ' . $response['body']['instance'];
			}

			if ( ! empty( $response['body']['title'] ) ) {
				$message = $response['body']['title'] . ': ' . $message;
			}

			return new WP_Error( $response_code, $message, rgar( $response['body'], 'errors' ) );
		}

		// Remove links from response.
		unset( $response['body']['_links'] );

		// If a return key is defined and array item exists, return it.
		if ( ! empty( $return_key ) && isset( $response['body'][ $return_key ] ) ) {
			return $response['body'][ $return_key ];
		}

		return $response['body'];

	}

	/**
	 * Set data center based on API key.
	 *
	 * @since  4.0
	 * @access private
	 */
	private function get_data_center() {

		// If API key is empty, return.
		if ( empty( $this->api_key ) ) {
			return;
		}

		if ( ! empty( $this->data_center ) ) {
			return $this->data_center;
		}

		$data_center = $this->get_data_center_from_api_key();

		return $data_center ? $data_center : 'us1';
	}

	private function get_data_center_from_api_key() {
		// Explode API key.
		$exploded_key = explode( '-', $this->api_key );

		// Set data center from API key.
		return isset( $exploded_key[1] ) ? $exploded_key[1] : false;
	}

	/**
	 * Get disconnect link.
	 *
	 * @since 4.10
	 *
	 * @return string
	 */
	public function get_disconnect_url() {
		return sprintf( 'https://%s.admin.mailchimp.com/account/api/', $this->data_center );
	}

}
