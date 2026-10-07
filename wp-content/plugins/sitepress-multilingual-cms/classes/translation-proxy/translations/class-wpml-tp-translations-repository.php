<?php

class WPML_TP_Translations_Repository {

	private $xliff_api;

	private $jobs_repository;

	public function __construct( WPML_TP_XLIFF_API $xliff_api, WPML_TM_Jobs_Repository $jobs_repository ) {
		$this->xliff_api       = $xliff_api;
		$this->jobs_repository = $jobs_repository;
	}

	public function get_job_translations( $job_id, $job_type, $parse = true ) {
		$job = $this->jobs_repository->get_job( $job_id, $job_type );

		if ( ! $job ) {
			throw new InvalidArgumentException( 'Cannot find job' );
		}

		return $this->get_job_translations_by_job_entity( $job, $parse );
	}

	public function get_job_translations_by_job_entity( WPML_TM_Job_Entity $job, $parse = true ) {
		$correct_states = array( ICL_TM_TRANSLATION_READY_TO_DOWNLOAD, ICL_TM_COMPLETE );
		if ( ! in_array( $job->get_status(), $correct_states, true ) ) {
			throw new InvalidArgumentException( 'Job\'s translation is not ready.' );
		}

		if ( ! $job->get_tp_id() ) {
			throw new InvalidArgumentException( 'This is only a local job.' );
		}

		$translations = $this->xliff_api->get_remote_translations( $job->get_tp_id(), $parse );

		if ( $parse ) {
			$translations = apply_filters( 'wpml_tm_proxy_translations', $translations, $job );
		}

		return $translations;
	}
}
