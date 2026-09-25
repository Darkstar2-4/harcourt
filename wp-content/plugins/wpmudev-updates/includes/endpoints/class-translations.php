<?php
/**
 * Translations functionality REST endpoint.
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
 * Class Translations
 */
class Translations extends Endpoint {

	/**
	 * API endpoint for the current endpoint.
	 *
	 * @since 5.0.0
	 * @var string $endpoint
	 */
	private string $endpoint = '/translations';

	/**
	 * Register the routes for handling translations functionality.
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
					'callback'            => array( $this, 'get_languages' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_translation' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'slug' => array(
							'required'    => true,
							'description' => __( 'Plugin slug to update translation.', 'wpmudev' ),
							'type'        => 'string',
						),
					),
				),
			)
		);
	}

	/**
	 * Get list of available languages.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_languages( WP_REST_Request $request ) {
		$locale = WPMUDEV_Dashboard::$site->get_option( 'translation_locale' );
		// Make sure function exists.
		if ( ! function_exists( '\wp_get_available_translations' ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		}
		// Get available translations.
		$translations = wp_get_available_translations();

		$languages = array();

		foreach ( get_available_languages() as $locale ) {
			if ( isset( $translations[ $locale ] ) ) {
				$languages[] = array(
					'language'    => $translations[ $locale ]['language'],
					'native_name' => $translations[ $locale ]['native_name'],
					'lang'        => current( $translations[ $locale ]['iso'] ),
				);

				// Remove installed language from available translations.
				unset( $translations[ $locale ] );
			} else {
				$languages[] = array(
					'language'    => $locale,
					'native_name' => $locale,
					'lang'        => '',
				);
			}
		}

		// Translation available.
		$translations_available = ! empty( $translations ) && current_user_can( 'install_languages' ) && wp_can_install_language_pack();

		// Add en_US.
		$languages[] = array(
			'language'    => 'en_US',
			'native_name' => 'English (United States)',
			'lang'        => '',
		);

		// List available translations.
		if ( $translations_available ) {
			foreach ( $translations as $translation ) {
				$languages[] = array(
					'language'    => esc_attr( $translation['language'] ),
					'native_name' => esc_html( $translation['native_name'] ),
					'lang'        => esc_attr( current( $translation['iso'] ) ),
				);
			}
		}

		return $this->get_response( $languages );
	}

	/**
	 * Update translations for a plugin.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function update_translation( WP_REST_Request $request ) {
		$slug = $request->get_param( 'slug' );

		// Update translations.
		$success = WPMUDEV_Dashboard::$upgrader->upgrade_translation( $slug );

		if ( $success ) {
			// Clear cache.
			WPMUDEV_Dashboard::$site->clear_local_file_cache();
		} else {
			// Return error if possible.
			$error = WPMUDEV_Dashboard::$upgrader->get_error();
			if ( isset( $error['code'], $error['message'] ) ) {
				return $this->get_error_response( $error['code'], $error['message'] );
			}
		}

		return $this->get_response( array(), $success );
	}
}