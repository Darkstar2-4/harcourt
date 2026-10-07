<?php

namespace WPML\TM\TranslationProxy\Services\Project;

class Manager {

	private $projectApi;

	private $projectStorage;

	private $siteDetails;

	public function __construct(
		\WPML_TP_Project_API $projectApi,
		Storage $projectStorage,
		SiteDetails $siteDetails
	) {
		$this->projectApi     = $projectApi;
		$this->projectStorage = $projectStorage;
		$this->siteDetails    = $siteDetails;
	}

	public function create( \stdClass $service ) {
		$project = $this->projectStorage->getByService( $service ) ?: $this->fromTranslationProxy( $service );

		$project->extraFields = $this->projectApi->get_extra_fields( $project );
		$this->projectStorage->save( $service, $project );

		do_action( 'wpml_tp_project_created', $service, $project, $this->projectStorage->getProjects()->toArray() );

		return $project;
	}

	public function updateCredentials( \stdClass $service, \stdClass $credentials ) {
		$project = $this->projectStorage->getByService( $service );
		if ( ! $project ) {
			throw new \RuntimeException( 'Project does not exist' );
		}
		$this->projectApi->update_project_credentials( $project, $credentials );

		$project->extraFields = $this->projectApi->get_extra_fields( $project );
		$this->projectStorage->save( $this->createServiceWithNewCredentials( $service, $credentials ), $project );

		return $project;
	}

	private function fromTranslationProxy( \stdClass $service ) {
		$response = $this->projectApi->create_project( $service, $this->siteDetails );

		return Project::fromResponse( $response->project );
	}

	private function createServiceWithNewCredentials( \stdClass $service, \stdClass $credentials ) {
		$updatedService                     = clone $service;
		$updatedService->custom_fields_data = $credentials;

		return $updatedService;
	}
}
