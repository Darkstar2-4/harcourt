<?php

use WPML\FP\Fns;

class WPML_TP_API_Services extends WPML_TP_Abstract_API {

	const ENDPOINT_SERVICES      = '/services.json';
	const ENDPOINT_SERVICE       = '/services/{service_id}.json';
	const ENDPOINT_LANGUAGES_MAP = '/services/{service_id}/language_identifiers.json';
	const ENDPOINT_CUSTOM_FIELDS = '/services/{service_id}/custom_fields.json';

	const TRANSLATION_MANAGEMENT_SYSTEM = 'tms';
	const PARTNER                       = 'partner';
	const TRANSLATION_SERVICE           = 'ts';
	const CACHED_SERVICES_KEY_DATA      = 'wpml_translation_services';
	const CACHED_SERVICES_TRANSIENT_KEY = 'wpml_translation_services_list';
	const CACHED_SERVICES_KEY_TIMESTAMP = 'wpml_translation_services_timestamp';

	private $endpoint;

	protected function get_endpoint_uri() {
		return $this->endpoint;
	}

	protected function is_authenticated() {
		return false;
	}

	public function get_all( $reload = false ) {
		$this->endpoint       = self::ENDPOINT_SERVICES;
		$translation_services = $reload ? null : $this->get_cached_services();

		if ( ! $translation_services || $this->has_cache_services_expired() ) {
			$fresh_translation_services = parent::get();

			if ( $fresh_translation_services ) {
				$translation_services = $this->convert_to_tp_services( $fresh_translation_services );
				$this->cache_services( $translation_services );
			}
		}

		return apply_filters( 'otgs_translation_get_services', $translation_services ? $translation_services : array() );
	}

	public function refresh_cache() {
		update_option( self::CACHED_SERVICES_KEY_TIMESTAMP, strtotime( '-2 day', $this->get_cached_services_timestamp() ) );
		return (bool) $this->get_all();
	}

	private function get_cached_services() {
		return get_option( self::CACHED_SERVICES_KEY_DATA );
	}

	private function get_cached_services_timestamp() {
		return get_option( self::CACHED_SERVICES_KEY_TIMESTAMP );
	}

	private function cache_services( $services ) {
		update_option( self::CACHED_SERVICES_KEY_DATA, $services, 'no' );
		update_option( self::CACHED_SERVICES_KEY_TIMESTAMP, time() );
	}

	private function has_cache_services_expired() {
		return time() >= strtotime( '+1 day', $this->get_cached_services_timestamp() );
	}

	private function convert_to_tp_services( $translation_services ) {
		return Fns::map( Fns::constructN( 1, \WPML_TP_Service::class ), $translation_services );
	}

	public function get_translation_services( $partner = true ) {
		return array_values(
			wp_list_filter(
				$this->get_all(),
				array(
					self::TRANSLATION_MANAGEMENT_SYSTEM => false,
					self::PARTNER                       => $partner,
				)
			)
		);
	}

	public function get_translation_management_systems() {
		return array_values( wp_list_filter( $this->get_all(), array( self::TRANSLATION_MANAGEMENT_SYSTEM => true ) ) );
	}

	public function get_active( $reload = false ) {
		return $this->get_one( $this->tp_client->get_project()->get_translation_service_id(), $reload );
	}

	public function get_name( $service_id, $reload = false ) {
		$translator_name = null;

		$translation_service = $this->get_one( $service_id, $reload );

		if ( null !== $translation_service && isset( $translation_service->name ) ) {
			$translator_name = $translation_service->name;
		}

		return $translator_name;
	}

	public function get_service( $service_id, $reload = false ) {
		return $this->get_one( $service_id, $reload );
	}

	private function get_one( $translation_service_id, $reload = false ) {
		$translation_service = null;
		if ( ! $translation_service_id ) {
			return $translation_service;
		}

		$translation_services = $this->get_all( $reload );
		$translation_services = wp_list_filter(
			$translation_services,
			array(
				'id' => (int) $translation_service_id,
			)
		);

		if ( $translation_services ) {
			$translation_service = current( $translation_services );
		} else {
			$translation_service = $this->get_unlisted_service( $translation_service_id );
		}

		return $translation_service;
	}

	private function get_unlisted_service( $translation_service_id ) {
		$this->endpoint = self::ENDPOINT_SERVICE;
		$service        = parent::get( array( 'service_id' => $translation_service_id ) );

		if ( $service instanceof stdClass ) {
			return new WPML_TP_Service( $service );
		}

		return null;
	}

	public function get_languages_map( $service_id ) {
		$this->endpoint = self::ENDPOINT_LANGUAGES_MAP;

		$args = array(
			'service_id' => $service_id,
		);

		return parent::get( $args );
	}

	public function get_custom_fields( $service_id ) {
		$this->endpoint = self::ENDPOINT_CUSTOM_FIELDS;

		$args = array(
			'service_id' => $service_id,
		);

		return parent::get( $args );
	}
}
