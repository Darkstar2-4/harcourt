<?php

namespace WPML\TM\API;

use WPML\FP\Either;
use WPML\TM\TranslationProxy\Services\AuthorizationFactory;

class TranslationServices {

	private $authorizationFactory;

	public function __construct( AuthorizationFactory $authorizationFactory ) {
		$this->authorizationFactory = $authorizationFactory;
	}

	public function selectBySUID( $suid ) {
		try {
			$service = \TranslationProxy_Service::get_service_by_suid( $suid );

			return $this->selectByServiceId( $service->id );
		} catch ( \Exception $e ) {
			return Either::left( sprintf( __( 'Service with SUID=%s cannot be found', ' sitepress-multilingual-cms' ), $suid ) );
		}
	}

	public function selectByServiceId( $serviceId ) {
		$result = \TranslationProxy::select_service( $serviceId );

		return \is_wp_error( $result ) ? Either::left( $result->get_error_message() ) : Either::of( $serviceId );
	}

	public function deselect() {
		if ( \TranslationProxy::get_current_service_id() ) {
			\TranslationProxy::clear_preferred_translation_service();
			\TranslationProxy::deselect_active_service();
		}
	}

	public function authorize( $apiToken ) {
		$authorization = $this->authorizationFactory->create();
		try {
			$authorization->authorize( (object) [ 'api_token' => $apiToken ] );

			return Either::of( true );
		} catch ( \Exception $e ) {
			$authorization->deauthorize();

			return Either::left( $e->getMessage() );
		}
	}

	public function getCurrentService() {
		$service = \TranslationProxy::get_current_service();

		return ! is_wp_error( $service ) ? $service : null;
	}

	public function isAnyActive() {
		return $this->getCurrentService() !== null;
	}

	public function isAuthorized() {
		return \TranslationProxy::is_current_service_active_and_authenticated();
	}
}
