<?php

namespace WPML\TM\ATE\API;

class AmsCredentialsStorage {

	private $auth;

	public function __construct( \WPML_TM_ATE_Authentication $auth ) {
		$this->auth = $auth;
	}

	public function store( array $responseBody ): bool {
		$registrationResult = $this->updateRegistrationData( $responseBody );
		$uuidResult         = $this->updateSiteUuId( $responseBody );

		return $registrationResult && $uuidResult;
	}

	private function updateRegistrationData( array $responseBody ): bool {
		$registrationData = get_option( \WPML_TM_ATE_Authentication::AMS_DATA_KEY, [] );

		$registrationData['secret'] = $responseBody['new_secret_key'];
		$registrationData['shared'] = $responseBody['new_shared_key'];

		return update_option( \WPML_TM_ATE_Authentication::AMS_DATA_KEY, $registrationData );
	}

	private function updateSiteUuId( array $responseBody ): bool {
		$this->auth->override_site_id( $responseBody['new_website_uuid'] );

		return update_option(
			\WPML_Site_ID::SITE_ID_KEY . ':ate',
			$responseBody['new_website_uuid'],
			false
		);
	}
}
