<?php

namespace Smush\Core;

class Configs_Controller extends Controller {
	/**
	 * @var Configs
	 */
	protected $configs;

	public function __construct() {
		$this->configs = Configs::get_instance();

		$this->register_action( 'wp_ajax_smush_upload_config', array( $this, 'upload_config' ) );
		$this->register_action( 'wp_ajax_smush_save_config', array( $this, 'save_config' ) );
		$this->register_action( 'wp_ajax_smush_apply_config', array( $this, 'apply_config' ) );
	}

	/**
	 * Get the instance of the Configs_Controller class.
	 */
	public static function get_instance() {
		return new Configs_Controller_Pro();
	}

	/**
	 * Handles the upload of a config file.
	 */
	public function upload_config() {
		check_ajax_referer( 'smush_handle_config' );

		$capability = is_multisite() ? 'manage_network' : 'manage_options';
		if ( ! Helper::is_user_allowed( $capability ) ) {
			wp_send_json_error( null, 403 );
		}

		/**
		 * Data escaped and sanitized via \Smush\Core\Configs::save_uploaded_config()
		 *
		 * @see \Smush\Core\Configs::decode_and_validate_config_file()
		 */
		$file = isset( $_FILES['file'] ) ? wp_unslash( $_FILES['file'] ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$new_config = $this->configs->save_uploaded_config( $file );

		if ( ! is_wp_error( $new_config ) ) {
			wp_send_json_success( $new_config );
		}

		wp_send_json_error(
			array( 'error_msg' => $new_config->get_error_message() )
		);
	}
	/**
	 * Handles the upload of a config file.
	 */
	public function save_config() {
		check_ajax_referer( 'smush_handle_config' );

		$capability = is_multisite() ? 'manage_network' : 'manage_options';
		if ( ! Helper::is_user_allowed( $capability ) ) {
			wp_send_json_error( null, 403 );
		}

		wp_send_json_success( $this->configs->get_config_from_current() );
	}

	/**
	 * Applies the given config.
	 */
	public function apply_config() {
		check_ajax_referer( 'smush_handle_config' );

		$capability = is_multisite() ? 'manage_network' : 'manage_options';
		if ( ! Helper::is_user_allowed( $capability ) ) {
			wp_send_json_error( null, 403 );
		}

		$id = filter_input( INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT );
		if ( ! $id ) {
			// Abort if no config ID was given.
			wp_send_json_error(
				array( 'error_msg' => esc_html__( 'Missing config ID', 'wp-smushit' ) )
			);
		}

		$response = $this->configs->apply_config_by_id( $id );

		if ( ! is_wp_error( $response ) ) {
			wp_send_json_success();
		}

		wp_send_json_error(
			array( 'error_msg' => esc_html( $response->get_error_message() ) )
		);
	}
}