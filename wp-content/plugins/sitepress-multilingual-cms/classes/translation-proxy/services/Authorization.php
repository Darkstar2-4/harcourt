<?php

namespace WPML\TM\TranslationProxy\Services;

use WPML\TM\TranslationProxy\Services\Project\Manager;

class Authorization {
	private $storage;

	private $projectManager;

	public function __construct( Storage $storage, Manager $projectManager ) {
		$this->storage        = $storage;
		$this->projectManager = $projectManager;
	}

	public function authorize( \stdClass $credentials ) {
		$service                     = $this->getCurrentService();
		$service->custom_fields_data = $credentials;

		$project = $this->projectManager->create( $service );
		$this->storage->setCurrentService( $service );

		do_action( 'wpml_tm_translation_service_authorized', $service, $project );
	}

	public function updateCredentials( \stdClass $credentials ) {
		$service = $this->getCurrentService();

		$this->projectManager->updateCredentials( $service, $credentials );

		$service->custom_fields_data = $credentials;
		$this->storage->setCurrentService( $service );
	}

	public function deauthorize() {
		$service                     = $this->getCurrentService();
		$service->custom_fields_data = null;

		$this->storage->setCurrentService( $service );

		do_action( 'wpml_tp_service_de_authorized', $service );
	}

	private function getCurrentService() {
		$service = $this->storage->getCurrentService();
		if ( (bool) $service === false ) {
			throw new \RuntimeException( 'Tried to authenticate a service, but no service is active!' );
		}

		return $service;
	}
}
