<?php

namespace Smush\Core;

use Smush\Core\Membership\Membership;

class Configs_Controller_Pro extends Configs_Controller {

	public function __construct() {
		parent::__construct();
		$this->register_action( 'wp_ajax_smush_hub_sync_config', array( $this, 'hub_sync_config' ) );
	}

	public function hub_sync_config() {
		check_ajax_referer( 'smush_handle_config' );

		$capability = is_multisite() ? 'manage_network' : 'manage_options';
		if ( ! Helper::is_user_allowed( $capability ) ) {
			wp_send_json_error( null, 403 );
		}

		$membership = Membership::get_instance();
		if ( ! $membership->is_pro() ) {
			wp_send_json_error( array( 'error_msg' => 'Pro membership required' ), 403 );
		}

		$api_key = $membership->get_apikey();
		if ( ! $api_key ) {
			wp_send_json_error( array( 'error_msg' => 'No API key found' ), 403 );
		}

		$config = $this->get_config_from_request();
		if ( ! $config ) {
			wp_send_json_error( array( 'error_msg' => 'Invalid config data' ) );
		}

		$response = $this->post_config_to_hub( $config, $api_key );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'error_msg' => $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 300 ) {
			wp_send_json_error( array( 'error_msg' => 'Hub API error: ' . $code ) );
		}

		wp_send_json_success( $body );
	}

	private function get_config_from_request() {
		$raw    = isset( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$config = json_decode( $raw, true );

		return is_array( $config ) ? $config : null;
	}

	private function post_config_to_hub( $config, $api_key ) {
		$hub_url = defined( 'WPMUDEV_CUSTOM_API_SERVER' ) && WPMUDEV_CUSTOM_API_SERVER
			? trailingslashit( WPMUDEV_CUSTOM_API_SERVER ) . 'api/hub/v1/package-configs'
			: 'https://wpmudev.com/api/hub/v1/package-configs';

		return wp_remote_post(
			$hub_url,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Basic ' . $api_key,
				),
				'body'    => wp_json_encode(
					array(
						'name'        => sanitize_text_field( $config['name'] ?? '' ),
						'description' => sanitize_text_field( $config['description'] ?? '' ),
						'package'     => array( 'name' => 'Smush', 'id' => '912164' ),
						'config'      => wp_json_encode( $config['config'] ?? array() ),
					)
				),
				'timeout' => 15,
			)
		);
	}
}