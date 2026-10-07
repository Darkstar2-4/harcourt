<?php

class WPML_TM_Sync_Jobs_Revision {

	private $jobs_repository;

	private $tp_api;

	public function __construct( WPML_TM_Jobs_Repository $jobs_repository, WPML_TP_Jobs_API $tp_api ) {
		$this->jobs_repository = $jobs_repository;
		$this->tp_api          = $tp_api;
	}

	public function sync() {
		$result = array();

		$tp_statuses_of_revised_jobs = $this->tp_api->get_revised_jobs();
		if ( $tp_statuses_of_revised_jobs ) {
			$revised_jobs = array();
			foreach ( $tp_statuses_of_revised_jobs as $tp_job_status ) {
				$revised_jobs[ $tp_job_status->get_tp_id() ] = $tp_job_status->get_revision();
			}

			$job_search = new WPML_TM_Jobs_Search_Params(
				array(
					'scope' => WPML_TM_Jobs_Search_Params::SCOPE_REMOTE,
					'tp_id' => array_keys( $revised_jobs ),
				)
			);

			foreach ( $this->jobs_repository->get( $job_search ) as $job ) {

				if ( isset( $revised_jobs[ $job->get_tp_id() ] ) && $job->get_revision() < $revised_jobs[ $job->get_tp_id() ] ) {

					$job->set_status( WPML_TP_Job_States::map_tp_state_to_local( WPML_TP_Job_States::TRANSLATION_READY ) );
					$job->set_revision( $revised_jobs[ $job->get_tp_id() ] );

					$result[] = $job;

					do_action( 'wpml_tm_revised_job_notification', $job->get_id() );
				}
			}
		}

		return $result;
	}

}

