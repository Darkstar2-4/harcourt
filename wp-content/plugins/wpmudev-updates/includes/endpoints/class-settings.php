<?php
/**
 * Settings functionality REST endpoint.
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
 * Class Settings
 */
class Settings extends Endpoint {

	/**
	 * API endpoint for the current endpoint.
	 *
	 * @since 5.0.0
	 * @var string $endpoint
	 */
	private string $endpoint = '/settings';

	/**
	 * Register the routes for handling settings functionality.
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
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'group' => array(
							'required'    => false,
							'description' => __( 'The settings group name.', 'wpmudev' ),
							'type'        => 'string',
							'enum'        => array_keys( WPMUDEV_Dashboard::$settings->defaults() ),
						),
						'name'  => array(
							'required'    => false,
							'description' => __( 'The settings item key.', 'wpmudev' ),
							'type'        => 'string',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'whitelabel' => array(
							'required'    => false,
							'description' => __( 'Whitelabel settings.', 'wpmudev' ),
							'type'        => 'object',
						),
						// Analytics options.
						'analytics'  => array(
							'required'    => false,
							'description' => __( 'Analytics settings.', 'wpmudev' ),
							'type'        => 'object',
						),
						// SSO.
						'sso'        => array(
							'required'    => false,
							'description' => __( 'SSO settings.', 'wpmudev' ),
							'type'        => 'object',
						),
						// Flags.
						'flags'      => array(
							'required'    => false,
							'description' => __( 'Setting flags.', 'wpmudev' ),
							'type'        => 'object',
						),
						// Data settings on uninstall.
						'data'       => array(
							'required'    => false,
							'description' => __( 'Data settings.', 'wpmudev' ),
							'type'        => 'object',
						),
						// Other small options.
						'general'    => array(
							'required'    => false,
							'description' => __( 'General settings.', 'wpmudev' ),
							'type'        => 'object',
						),
					),
				),
			)
		);

		// Routes to reset settings.
		register_rest_route(
			$this->get_namespace(),
			$this->endpoint . '/reset',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'reset_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Get the settings value from DB.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings( WP_REST_Request $request ): WP_REST_Response {
		$name  = $request->get_param( 'name' );
		$group = $request->get_param( 'group' );

		// If name and group is not given, return all values.
		if ( empty( $name ) && empty( $group ) ) {
			$settings = WPMUDEV_Dashboard::$settings->get_all(
				array(
					'updates_data',
					'remote_access',
				)
			);
		} elseif ( empty( $name ) ) {
			// If name is not given, get group values.
			$settings = WPMUDEV_Dashboard::$settings->get_option( $group, array() );
		} else {
			// Get specific item.
			$settings = WPMUDEV_Dashboard::$settings->get( $name, $group );
		}

		$settings = $this->exclude_sensitive_settings( $settings, $name );

		return $this->get_response( $settings );
	}

	/**
	 * Get the post stats data from cache or API.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ) {
		$values = $request->get_json_params();

		if ( empty( $values ) ) {
			return $this->get_error_response( 'empty_settings', __( 'No settings found for update', 'wpmudev' ), $values );
		}

		// Get the default settings.
		$defaults         = WPMUDEV_Dashboard::$settings->defaults();
		$internal_options = WPMUDEV_Dashboard::$settings->get_internal_options();
		$allowed_options  = array_diff( array_keys( $defaults ), $internal_options );
		$invalid_options  = array_diff( array_keys( $values ), $allowed_options );

		// If invalid options found.
		if ( ! empty( $invalid_options ) ) {
			return $this->get_error_response(
				'invalid_settings',
				__( 'Invalid setting item(s).', 'wpmudev' ),
				array( 'invalid_items' => $invalid_options )
			);
		}

		$values = $this->maybe_integrate_settings( $values );
		if ( is_a( $values, 'WP_REST_Response' ) ) {
			return $values;
		}

		// Update each items.
		foreach ( $values as $group => $value ) {
			if ( ! empty( $value ) ) {
				WPMUDEV_Dashboard::$settings->set( $group, $value );
			}
		}

		// Get updated options.
		$settings = WPMUDEV_Dashboard::$settings->get_all(
			array(
				'updates_data',
				'remote_access',
			)
		);
		$settings = $this->exclude_sensitive_settings( $settings );

		return $this->get_response( $settings );
	}

	/**
	 * Exclude sensitive internal settings from REST responses.
	 *
	 * @since 5.0.2
	 *
	 * @param mixed       $settings Settings response data.
	 * @param string|null $name     Requested setting name.
	 *
	 * @return mixed
	 */
	private function exclude_sensitive_settings( $settings, ?string $name = null ) {
		if ( 'step1_hmac' === $name ) {
			return null;
		}

		if ( ! is_array( $settings ) ) {
			return $settings;
		}

		unset( $settings['step1_hmac'] );

		if ( isset( $settings['sso'] ) && is_array( $settings['sso'] ) ) {
			unset( $settings['sso']['step1_hmac'] );
		}

		return $settings;
	}

	/**
	 * Reset plugin settings back to defaults.
	 *
	 * @since 5.0.0
	 *
	 * @return WP_REST_Response
	 */
	public function reset_settings(): WP_REST_Response {
		// Prepare Analytics upstream state update.
		$analytics_enabled = (bool) WPMUDEV_Dashboard::$settings->get( 'enabled', 'analytics' );

		// Reset settings.
		WPMUDEV_Dashboard::$settings->reset();

		// trigger refresh, after reset, if user still logged in.
		if ( WPMUDEV_Dashboard::$api->has_key() ) {
			if ( $analytics_enabled ) { // also disable analytics if it was enabled before.
				WPMUDEV_Dashboard::$api->analytics_disable();
			}

			WPMUDEV_Dashboard::$settings->set( 'refresh_profile', true, 'flags' );
			if ( empty( WPMUDEV_Dashboard::$api->get_projects_data()['projects'] ) ) { // only when its empty. User can trigger manual recheck outside reset.
				WPMUDEV_Dashboard::$api->refresh_projects_data();
			}
			WPMUDEV_Dashboard::$site->refresh_local_projects( 'remote', true );
		}

		return $this->get_response();
	}

	/**
	 * Integrate settings with external services / plugins.
	 *
	 * @since 5.0.0
	 *
	 * @param array $settings Settings values.
	 *
	 * @return array|WP_REST_Response
	 */
	private function maybe_integrate_settings( array $settings ) {
		/**
		 * Override settings before integrating with external services / plugins.
		 *
		 * @since 5.0.0
		 */
		$settings = apply_filters( 'wpmudev_dashboard_settings_before_integrate', $settings );
		if ( isset( $settings['analytics'] ) ) {
			$current_enabled   = (bool) WPMUDEV_Dashboard::$settings->get( 'enabled', 'analytics' );
			$requested_enabled = (bool) ( $settings['analytics']['enabled'] ?? false );

			// enabled state change request.
			if ( $current_enabled !== $requested_enabled ) {
				if ( $requested_enabled ) {
					$success = WPMUDEV_Dashboard::$api->analytics_enable();
				} else {
					$success = WPMUDEV_Dashboard::$api->analytics_disable();
				}

				if ( ! $success ) {
					return $this->get_error_response( 'failed_update_analytics', __( 'Failed to update analytics settings.', 'wpmudev' ) );
				}
				WPMUDEV_Dashboard::$settings->set( 'enabled', $requested_enabled, 'analytics' );
			}

			// except metrics and role, everything else should not be updated by frontend values.
			foreach ( $settings['analytics'] as $key => $value ) {
				if ( ! in_array( $key, array( 'metrics', 'role' ), true ) ) {
					$settings['analytics'][ $key ] = WPMUDEV_Dashboard::$settings->get( $key, 'analytics' );
				}
			}
		}

		// SSO.
		if ( isset( $settings['sso'] ) ) {
			$current_enabled   = (bool) WPMUDEV_Dashboard::$settings->get( 'enabled', 'sso' );
			$requested_enabled = (bool) ( $settings['sso']['enabled'] ?? false );

			if ( $current_enabled !== $requested_enabled ) {
				if ( $requested_enabled ) {
					WPMUDEV_Dashboard::$settings->set( 'userid', get_current_user_id(), 'sso' );
				}
				WPMUDEV_Dashboard::$settings->set( 'enabled', $requested_enabled, 'sso' );
				// Also, force a hub-sync, since the SSO setting changed.
				WPMUDEV_Dashboard::$site->schedule_shutdown_refresh();

				$settings['sso'] = WPMUDEV_Dashboard::$settings->get_option( 'sso', array() );
			}
		}

		$calculate_translations_upgrade_hit = false;
		$calculate_translations_upgrade     = fn() => WPMUDEV_Dashboard::$api->calculate_translation_upgrades( true );

		if ( isset( $settings['flags'] ) ) {
			$flags = $settings['flags'];
			// Translations trigger calculate.
			if ( isset( $flags['enable_auto_translation'] ) ) {
				$current_enabled   = (bool) WPMUDEV_Dashboard::$settings->get( 'enable_auto_translation', 'flags' );
				$requested_enabled = (bool) $flags['enable_auto_translation'];
				if ( $current_enabled !== $requested_enabled && $requested_enabled ) {
					$calculate_translations_upgrade();
					$calculate_translations_upgrade_hit = true;
				}
			}
		}

		if ( isset( $settings['general'] ) ) {
			$general = $settings['general'];
			// Locale.
			if ( isset( $general['translation_locale'] ) ) {
				$current_locale   = WPMUDEV_Dashboard::$settings->get( 'translation_locale', 'general' );
				$requested_locale = $general['translation_locale'];
				if ( $current_locale !== $requested_locale && ! $calculate_translations_upgrade_hit ) {
					$calculate_translations_upgrade();
				}
			}

			// limit_to_user.
			if ( isset( $general['limit_to_user'] ) ) {
				$current_limit_to_user = WPMUDEV_Dashboard::$site->get_allowed_users_from_settings();

				$requested_limit_to_user = $general['limit_to_user'];
				$requested_limit_to_user = is_array( $requested_limit_to_user ) ? $requested_limit_to_user : array();
				$requested_limit_to_user = array_map( 'intval', $requested_limit_to_user );

				// Current user should always be there in the list.
				if ( ! in_array( get_current_user_id(), $requested_limit_to_user, true ) ) {
					$requested_limit_to_user[] = get_current_user_id();
				}

				// to add.
				foreach ( $requested_limit_to_user as $user_id ) {
					if ( ! in_array( $user_id, $current_limit_to_user, true ) ) {
						$success = WPMUDEV_Dashboard::$site->add_allowed_user( $user_id );
						if ( ! $success ) {
							return $this->get_error_response( 'failed_add_allowed_user', __( 'Failed to add allowed user.', 'wpmudev' ) );
						}
					}
				}
				// to remove.
				foreach ( $current_limit_to_user as $user_id ) {
					if ( ! in_array( $user_id, $requested_limit_to_user, true ) ) {
						// Do not let self delete.
						if ( get_current_user_id() === $user_id ) {
							return $this->get_error_response( 'cannot_remove_self', __( 'You cannot remove yourself from the allowed users list.', 'wpmudev' ) );
						}
						$success = WPMUDEV_Dashboard::$site->remove_allowed_user( $user_id );
						if ( ! $success ) {
							return $this->get_error_response( 'failed_remove_allowed_user', __( 'Failed to remove allowed user.', 'wpmudev' ) );
						}
					}
				}

				$general['limit_to_user'] = WPMUDEV_Dashboard::$site->get_allowed_users_from_settings();

				$settings['general'] = $general;
			}
		}

		// sanitize whitelabel settings.
		if ( isset( $settings['whitelabel'] ) ) {
			$allowed_tags = array(
				'a'      => array(
					'href'   => array(),
					'title'  => array(),
					'target' => array(),
				),
				'b'      => array(),
				'i'      => array(),
				'strong' => array(),
			);
			$whitelabel   = $settings['whitelabel'];
			foreach ( $whitelabel as $key => $value ) {
				if ( is_string( $value ) ) {
					if ( 'footer_text' === $key ) {
						$whitelabel[ $key ] = wp_kses( $value, $allowed_tags );
					} else {
						$whitelabel[ $key ] = sanitize_text_field( $value );
					}
				} elseif ( is_bool( $value ) ) {
					$whitelabel[ $key ] = wp_validate_boolean( $value );
				} elseif ( is_array( $value ) ) {
					if ( 'labels_config' === $key ) {
						$config_values = array();
						foreach ( $value as $plugin => $config ) {
							if ( is_array( $config ) ) {
								// Make sure the format.
								$values = wp_parse_args(
									$config,
									array(
										'name'      => '',
										'icon_type' => 'default',
									)
								);
								// Set values.
								$config_values[ $plugin ] = array_map( 'sanitize_text_field', $values );
							}
						}
						$whitelabel[ $key ] = $config_values;
					} elseif ( 'labels_subsites' === $key ) {
						// int.
						$value = array_map( 'intval', $value );
						// unique.
						$value              = array_unique( $value );
						$whitelabel[ $key ] = $value;
					}
				}
			}
			$settings['whitelabel'] = $whitelabel;
		}

		// unchange-able settings via UI.
		$unchangeable = array(
			'flags'   => array( 'refresh_remote', 'refresh_profile', 'redirected_v4' ),
			// staff_notes update-able via /support-access endpoint.
			'general' => array( 'last_run_updates', 'last_run_profile', 'last_run_sync', 'last_run_translation', 'version', 'auth_user', 'hub_nonce', 'connected_admin', 'site_info', 'staff_notes' ),
		);

		foreach ( $settings as $group => $value ) {
			if ( isset( $unchangeable[ $group ] ) && is_array( $value ) ) {
				foreach ( $unchangeable[ $group ] as $key ) {
					if ( isset( $value[ $key ] ) ) {
						$settings[ $group ][ $key ] = WPMUDEV_Dashboard::$settings->get( $key, $group );
					}
				}
			}
		}

		// Keep the temporary SSO authentication value server-owned.
		if ( isset( $settings['sso'] ) && is_array( $settings['sso'] ) ) {
			$settings['sso']['step1_hmac'] = WPMUDEV_Dashboard::$settings->get( 'step1_hmac', 'sso', '' );
		}

		return $settings;
	}
}