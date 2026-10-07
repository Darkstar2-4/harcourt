<?php

use WPML\Core\Component\PostHog\Application\Service\Event\EventInstanceService;

class WPML_Global_AJAX extends WPML_SP_User {

	public function __construct( &$sitepress ) {
		parent::__construct( $sitepress );
		add_action( 'wp_ajax_save_language_negotiation_type', array( $this, 'save_language_negotiation_type_action' ) );
	}

	public function save_language_negotiation_type_action() {
		if ( ! current_user_can( 'wpml_manage_languages' ) ) {
			wp_send_json_error( __( "You can't do that!", 'sitepress' ) );

			return;
		}

		$errors         = array();
		$response       = false;
		$nonce          = filter_input( INPUT_POST, 'nonce' );
		$action         = filter_input( INPUT_POST, 'action' );
		$is_valid_nonce = wp_verify_nonce( $nonce, $action );

		$postHogCaptureEventData = [];

		if ( $is_valid_nonce ) {
			$icl_language_negotiation_type = filter_input( INPUT_POST, 'icl_language_negotiation_type', FILTER_SANITIZE_NUMBER_INT, FILTER_NULL_ON_FAILURE );
			$language_domains              = filter_input( INPUT_POST, 'language_domains', FILTER_SANITIZE_FULL_SPECIAL_CHARS, FILTER_REQUIRE_ARRAY | FILTER_NULL_ON_FAILURE );
			$use_directory                 = filter_input( INPUT_POST, 'use_directory', FILTER_SANITIZE_NUMBER_INT, FILTER_NULL_ON_FAILURE );
			$show_on_root                  = filter_input( INPUT_POST, 'show_on_root', FILTER_SANITIZE_FULL_SPECIAL_CHARS, FILTER_NULL_ON_FAILURE );
			$root_html_file_path           = filter_input( INPUT_POST, 'root_html_file_path', FILTER_SANITIZE_FULL_SPECIAL_CHARS, FILTER_NULL_ON_FAILURE );
			$hide_language_switchers       = filter_input( INPUT_POST, 'hide_language_switchers', FILTER_SANITIZE_NUMBER_INT, FILTER_NULL_ON_FAILURE );
			$icl_xdomain_data              = filter_input( INPUT_POST, 'xdomain', FILTER_SANITIZE_NUMBER_INT, FILTER_NULL_ON_FAILURE );
			$sso_enabled                   = filter_input( INPUT_POST, 'sso_enabled', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

			if ( $icl_language_negotiation_type ) {
				$this->sitepress->set_setting( 'language_negotiation_type', $icl_language_negotiation_type );

				$postHogCaptureEventData['language_negotiation_type'] = \WPML\Core\LanguageNegotiation::getModeAsString( $icl_language_negotiation_type );
				$response = true;

				if ( ! empty( $language_domains ) ) {
					$this->sitepress->set_setting( 'language_domains', $language_domains );

					$postHogCaptureEventData['language_domains'] = $language_domains;
				}

				if ( 1 === (int) $icl_language_negotiation_type ) {
					$urls                                   = $this->sitepress->get_setting( 'urls' );
					$urls['directory_for_default_language'] = $use_directory ? true : 0;

					$postHogCaptureEventData['directory_for_default_language'] = $use_directory ? true : false;

					if ( $use_directory ) {
						$urls['show_on_root'] = $use_directory ? $show_on_root : '';

						$postHogCaptureEventData['show_on_root'] = $urls['show_on_root'];

						if ( 'html_file' === $show_on_root ) {
							$root_page_url = $root_html_file_path ? $root_html_file_path : '';
							$response      = $this->validateRootPageUrl( $root_page_url, $errors );
							if ( $response ) {
								$urls['root_html_file_path'] = $root_page_url;

								$postHogCaptureEventData['root_html_file_path'] = $urls['root_html_file_path'];
							}
						} else {
							$urls['hide_language_switchers'] = $hide_language_switchers ? $hide_language_switchers : 0;

							$root_page_id = isset( $urls['root_page'] ) ? $urls['root_page'] : 0;
							$root_page_exists = false;

							if ( $root_page_id > 0 ) {
								$root_page = get_post( $root_page_id );
								$root_page_exists = ( $root_page && $root_page->post_status !== 'trash' );
							}

							$postHogCaptureEventData['root_page_created'] = $root_page_exists;
							$postHogCaptureEventData['root_page_id'] = $root_page_id;
							$postHogCaptureEventData['hide_language_switchers'] = (bool) $urls['hide_language_switchers'];
						}
					}

					$this->sitepress->set_setting( 'urls', $urls );
				}

				$this->sitepress->set_setting( 'xdomain_data', $icl_xdomain_data );
				$this->sitepress->set_setting( 'language_per_domain_sso_enabled', $sso_enabled );

				$postHogCaptureEventData['xdomain_data'] = $icl_xdomain_data == WPML_XDOMAIN_DATA_GET ? 'GET' :
					( $icl_xdomain_data == WPML_XDOMAIN_DATA_POST ? 'POST' : 'OFF' );

				$postHogCaptureEventData['language_per_domain_sso_enabled'] = $sso_enabled;

				$this->sitepress->save_settings();

				( new WPML_WP_Cache( WPML_Tax_Permalink_Filters::CACHE_GROUP ) )->flush_group_cache();
			}

			if ( $response ) {
				$permalinks_settings_url = get_admin_url( null, 'options-permalink.php' );
				$save_permalinks_link    = '<a href="' . $permalinks_settings_url . '">' . _x( 're-save the site permalinks', 'You may need to {re-save the site permalinks} - 2/2', 'sitepress' ) . '</a>';
				$save_permalinks_message = sprintf( _x( 'You may need to %s.', 'You may need to {re-save the site permalinks} - 1/2', 'sitepress' ), $save_permalinks_link );

				$this->postHogCaptureLanguageNegotiationData($postHogCaptureEventData);

				wp_send_json_success( $save_permalinks_message );
			} else {
				if ( ! $errors ) {
					$errors[] = __( 'Error', 'sitepress' );
				}

				$this->postHogCaptureFailedLanguageNegotiationSave( $errors );

				wp_send_json_error( $errors );
			}
		}
	}


	private function postHogCaptureLanguageNegotiationData( $eventData = [] ) {
		$eventData['source'] = 'languages_page';

		\WPML\PostHog\Event\CaptureEvent::capture(
			( new EventInstanceService() )->getSetLanguageUrlFormatEvent( $eventData )
		);
	}


	private function postHogCaptureFailedLanguageNegotiationSave( $errors ) {
		\WPML\PostHog\Event\CaptureEvent::capture(
			( new EventInstanceService() )->getSetLanguageUrlFormatFailedEvent( $errors )
		);
	}

	private function validateRootPageUrl( $url, array &$errors ) {
		$wp_http = new WP_Http();
		if ( '' === trim( $url ) ) {
			$errors[] = __( 'The URL of the HTML file is required', 'sitepress' );

			return false;
		}
		if ( 0 !== strpos( $url, 'http' ) ) {
			$url = get_site_url( null, $url );
		}

		if ( $this->is_external( $url ) ) {
			$errors[] = __( 'You are trying to use an external URL: this is not allowed.', 'sitepress' );

			return false;
		}

		try {
			$response = $wp_http->get( $url );
			if ( is_wp_error( $response ) ) {
				$errors[] = $response->get_error_code() . ' - ' . $response->get_error_message( $response->get_error_code() );

				return false;
			}
			if ( 200 !== (int) $response['response']['code'] ) {
				$errors[] = __( 'An attempt to open the URL specified as a root page failed with the following error:', 'sitepress' );
				$errors[] = $response['response']['code'] . ': ' . $response['response']['message'];

				return false;
			}
		} catch ( Exception $ex ) {
			$errors[] = $ex->getMessage();

			return false;
		}

		return true;
	}

	function is_external( $url ) {
		$site_url        = get_site_url();
		$site_components = wp_parse_url( $site_url );
		$site_host       = strtolower( $site_components['host'] );

		$url_components = wp_parse_url( $url );
		$url_host       = strtolower( $url_components['host'] );

		if ( empty( $url_host ) || 0 === strcasecmp( $url_host, $site_host ) ) {
			return false;
		}

		$site_host = $this->remove_www_prefix( $site_host );

		$subdomain_position = strrpos( $url_host, '.' . $site_host );
		$subdomain_length   = strlen( $url_host ) - strlen( '.' . $site_host );

		return $subdomain_position !== $subdomain_length;
	}

	function remove_www_prefix( $site_host ) {
		$site_host_levels = explode( '.', $site_host );
		if ( 2 > count( $site_host_levels ) && 'www' === $site_host_levels[0] ) {
			$site_host_levels = array_slice( $site_host_levels, - ( count( $site_host_levels ) - 1 ) );
			$site_host        = implode( '.', $site_host_levels );
		}

		return $site_host;
	}
}
