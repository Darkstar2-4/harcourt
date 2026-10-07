<?php

namespace Smush\Core;

use Smush\Core\CDN\CDN_Status;
use Smush\Core\Membership\Membership;

class Plugin_Settings_Watcher extends Controller {
	/**
	 * @var Settings
	 */
	private $settings;
	/**
	 * @var Array_Utils
	 */
	private $array_utils;
	private $membership;

	public function __construct() {
		$this->settings    = Settings::get_instance();
		$this->array_utils = new Array_Utils();
		$this->membership = Membership::get_instance();

		$this->hook_settings_update_interceptor( array( $this, 'trigger_updated_action' ) );
		$this->hook_settings_delete_interceptor( array( $this, 'trigger_deleted_action' ) );

		$this->hook_settings_update_interceptor( array(
			$this,
			'trigger_resize_sizes_updated_action',
		), 'wp-smush-resize_sizes' );

		$this->hook_settings_update_interceptor( array(
			$this,
			'trigger_membership_status_change_action',
		), 'wp_smush_api_auth' );

		// Bulk Image Sizes.
		$this->hook_settings_update_interceptor( array(
			$this,
			'trigger_image_sizes_updated_action',
		), 'wp-smush-image_sizes' );
		$this->hook_settings_delete_interceptor( array(
			$this,
			'trigger_image_sizes_deleted_action'
		), 'wp-smush-image_sizes' );
		$this->hook_settings_add_interceptor( array(
			$this,
			'trigger_image_sizes_added_action'
		), 'wp-smush-image_sizes' );

		$this->hook_settings_update_interceptor( array(
			$this,
			'trigger_lazy_load_updated_action',
		), 'wp-smush-lazy_load' );

		$this->hook_settings_update_interceptor( array(
			$this,
			'trigger_cdn_status_updated_action',
		), 'wp-smush-cdn_status' );
	}

	private function hook_settings_update_interceptor( $callback, $option_id = 'wp-smush-settings' ) {
		if ( $this->settings->is_network_setting( $option_id ) ) {
			$this->register_action(
				"update_site_option_$option_id",
				function ( $option, $settings, $old_settings ) use ( $callback ) {
					call_user_func_array( $callback, array( $old_settings, $settings ) );
				},
				10,
				3
			);
		} else {
			$this->register_action( "update_option_$option_id", $callback, 10, 2 );
		}
	}

	private function hook_settings_delete_interceptor( $callback, $option_id = 'wp-smush-settings' ) {
		if ( $this->settings->is_network_enabled() ) {
			$this->register_action( "delete_site_option_{$option_id}", $callback );
		} else {
			$this->register_action( "delete_option_{$option_id}", $callback );
		}
	}

	private function hook_settings_add_interceptor( $callback, $option_id = 'wp-smush-settings' ) {
		if ( $this->settings->is_network_enabled() ) {
			$this->register_action( "add_site_option_{$option_id}", $callback );
		} else {
			$this->register_action( "add_option_{$option_id}", $callback );
		}
	}

	public function trigger_updated_action( $old_settings, $settings ) {
		do_action( 'wp_smush_settings_updated', $old_settings, $settings );
	}

	public function trigger_deleted_action() {
		do_action( 'wp_smush_settings_deleted' );
	}

	public function trigger_resize_sizes_updated_action( $old_settings, $settings ) {
		do_action( 'wp_smush_resize_sizes_updated', $old_settings, $settings );
	}

	public function trigger_image_sizes_updated_action( $old_settings, $settings ) {
		do_action( 'wp_smush_image_sizes_updated', $old_settings, $settings );
	}

	public function trigger_image_sizes_deleted_action() {
		do_action( 'wp_smush_image_sizes_deleted' );
	}

	public function trigger_image_sizes_added_action() {
		do_action( 'wp_smush_image_sizes_added' );
	}

	public function trigger_membership_status_change_action( $old_settings, $settings ) {
		$api_key      = $this->membership->get_apikey();
		$old_validity = isset( $old_settings[ $api_key ]['validity'] ) ? $old_settings[ $api_key ]['validity'] : false;
		$new_validity = isset( $settings[ $api_key ]['validity'] ) ? $settings[ $api_key ]['validity'] : false;

		if ( $old_validity !== $new_validity ) {
			do_action( 'wp_smush_membership_status_changed' );
		}
	}

	public function trigger_lazy_load_updated_action( $old_settings, $settings ) {
		do_action( 'wp_smush_lazy_load_updated', $old_settings, $settings );
	}

	public function trigger_cdn_status_updated_action( $old_status, $new_status ) {
		$old = CDN_Status::from_setting( $old_status );
		$new = CDN_Status::from_setting( $new_status );

		if ( ! $old || ! $new ) {
			return;
		}

		// Transition: provisioning in progress → fully active.
		if ( $old->is_cdn_enabling() && $new->is_cdn_enabled() ) {
			do_action( 'wp_smush_cdn_activated', $new );
		}
	}
}