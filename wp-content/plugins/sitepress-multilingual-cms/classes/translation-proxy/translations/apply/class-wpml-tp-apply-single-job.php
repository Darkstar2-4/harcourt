<?php

class WPML_TP_Apply_Single_Job {
	private $translations_repository;

	private $strategy_dispatcher;

	public function __construct(
		WPML_TP_Translations_Repository $translations_repository,
		WPML_TP_Apply_Translation_Strategies $strategy_dispatcher
	) {
		$this->translations_repository = $translations_repository;
		$this->strategy_dispatcher     = $strategy_dispatcher;
	}

	public function apply( WPML_TM_Job_Entity $job ) {
		$translations = $this->translations_repository->get_job_translations_by_job_entity( $job );

		$this->strategy_dispatcher->get( $job )->apply( $job, $translations );
		$job->set_status( ICL_TM_COMPLETE );

		return $job;
	}
}
