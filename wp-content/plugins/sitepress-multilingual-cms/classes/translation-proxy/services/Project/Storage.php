<?php

namespace WPML\TM\TranslationProxy\Services\Project;

use WPML\Collect\Support\Collection;

class Storage {
	private $sitepress;

	public function __construct( \SitePress $sitepress ) {
		$this->sitepress = $sitepress;
	}

	public function getByService( \stdClass $service ) {
		return $this->getProjects()->get( \TranslationProxy_Project::generate_service_index( $service ) );
	}

	public function save( \stdClass $service, Project $project ) {
		$index = \TranslationProxy_Project::generate_service_index( $service );
		$this->sitepress->set_setting(
			'icl_translation_projects',
			$this->getProjects()->put( $index, $project )->map(
				function ( Project $project ) {
					return $project->toArray();
				}
			)->toArray(),
			true
		);
	}

	public function getProjects() {
		$projects = $this->sitepress->get_setting( 'icl_translation_projects', [] );
		if ( ! is_array( $projects ) ) {
			$projects = [];
		}

		return \wpml_collect( $projects )->map(
			function ( array $rawProject ) {
				return Project::fromArray( $rawProject );
			}
		);
	}
}
