<?php

namespace WPML\TM\ATE\ClonedSites\SetupMigration;

class AmsApiTester {

	private $api;

	public function __construct( \WPML_TM_ATE_API $api ) {
		$this->api = $api;
	}

	public function hasSiteBeenMigratedToNewDomain(): bool {
		$response = $this->api->sync_all( [ 123 ] );

		if ( ! is_wp_error( $response ) ) {
			return false;
		}

		return $response->get_error_code() === 426;
	}
}
