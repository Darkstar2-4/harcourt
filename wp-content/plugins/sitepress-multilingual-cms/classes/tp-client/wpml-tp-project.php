<?php

class WPML_TP_Project {

	private $translation_service;

	private $translation_projects;

	private $project;

	public function __construct( $translation_service, $translation_projects ) {
		$this->translation_service  = $translation_service;
		$this->translation_projects = $translation_projects;
	}

	private function init() {
		if ( ! $this->project ) {
			$project_index = TranslationProxy_Project::generate_service_index( $this->translation_service );

			if ( isset( $this->translation_projects [ $project_index ] ) ) {
				$this->project = $this->translation_projects[ $project_index ];
			}
		}
	}

	public function get_translation_service_id() {
		return isset( $this->translation_service->id ) ? (int) $this->translation_service->id : null;
	}

	public function get_access_key() {
		return $this->get_project_property( 'access_key' );
	}

	public function get_id() {
		return (int) $this->get_project_property( 'id' );
	}

	private function get_project_property( $project_property ) {
		$this->init();
		return isset( $this->project[ $project_property ] ) ? $this->project[ $project_property ] : null;
	}
}
