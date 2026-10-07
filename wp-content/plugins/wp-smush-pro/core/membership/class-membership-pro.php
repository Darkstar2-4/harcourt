<?php

namespace Smush\Core\Membership;

use Smush\Core\Api\Smush_API;

class Membership_Pro extends Membership {
	protected $is_pro;

	public function get_apikey() {
		// If API key defined manually, get that.
		if ( defined( 'WPMUDEV_APIKEY' ) && WPMUDEV_APIKEY ) {
			return WPMUDEV_APIKEY;
		}

		// If dashboard plugin is active, get API key from db.
		if ( class_exists( 'WPMUDEV_Dashboard' ) ) {
			return get_site_option( 'wpmudev_apikey' );
		}

		return false;
	}

	public function validate_install( $force = false ) {
		if ( $this->is_pro && ! $force ) {
			return;
		}

		$api_key = $this->get_apikey();
		if ( empty( $api_key ) ) {
			return;
		}

		// Flag to check if we need to revalidate the key.
		$revalidate = false;

		$api_auth = get_site_option( 'wp_smush_api_auth' );

		// Check if we need to revalidate.
		if ( empty( $api_auth[ $api_key ] ) ) {
			$api_auth   = array();
			$revalidate = true;
		} else {
			$last_checked = $api_auth[ $api_key ]['timestamp'];
			$valid        = $api_auth[ $api_key ]['validity'];

			// Difference in hours.
			$diff = ( time() - $last_checked ) / HOUR_IN_SECONDS;
			if ( 24 < $diff ) {
				$revalidate = true;
			}
		}

		// If we are supposed to validate API, update the results in options table.
		if ( $revalidate || $force ) {
			if ( empty( $api_auth[ $api_key ] ) ) {
				// For api key resets.
				$api_auth[ $api_key ] = array();

				// Storing it as valid, unless we really get to know from API call.
				$valid                            = 'valid';
				$api_auth[ $api_key ]['validity'] = 'valid';
			}

			// This is the first check.
			if ( ! isset( $api_auth[ $api_key ]['timestamp'] ) ) {
				$api_auth[ $api_key ]['timestamp'] = time();
			}

			$api     = new Smush_API( $api_key );
			$request = $api->check( $force );

			if ( ! is_wp_error( $request ) && 200 === wp_remote_retrieve_response_code( $request ) ) {
				// Update the timestamp only on successful attempts.
				$api_auth[ $api_key ]['timestamp'] = time();
				update_site_option( 'wp_smush_api_auth', $api_auth );

				$result = json_decode( wp_remote_retrieve_body( $request ) );
				if ( ! empty( $result->success ) ) {
					$valid = 'valid';
					update_site_option( 'wp-smush-cdn_status', $result->data );
				} else {
					$valid = 'invalid';
				}
			} elseif ( ! isset( $valid ) || 'valid' !== $valid ) {
				// Invalidate only in case when it was not valid before.
				$valid = 'invalid';
			}

			$api_auth[ $api_key ]['validity'] = $valid;

			// Update API validity.
			update_site_option( 'wp_smush_api_auth', $api_auth );
		}

		$this->is_pro = isset( $valid ) && 'valid' === $valid;
	}

	public function has_access_to_hub() {
		if ( $this->is_pro() ) {
			return true;
		}

		return parent::has_access_to_hub();
	}

	public function can_use_current_compression_level() {
		if ( $this->is_pro() ) {
			return true;
		}

		return parent::can_use_current_compression_level();
	}

	public function get_member_value( $value = true, $alt = null ) {
		return $this->is_pro() ? $value : $alt;
	}
}