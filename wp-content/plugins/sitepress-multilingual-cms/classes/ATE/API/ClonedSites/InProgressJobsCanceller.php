<?php

namespace WPML\TM\ATE\ClonedSites;

class InProgressJobsCanceller {

	private $ateJobsRepository;

	private $updateJobs;

	private $translationJobFactory;

	public function __construct(
		\WPML_TM_ATE_Job_Repository $ateJobsRepository,
		\WPML_TP_Sync_Update_Job $updateJobs,
		\WPML_Translation_Job_Factory $translationJobFactory
	) {
		$this->ateJobsRepository     = $ateJobsRepository;
		$this->updateJobs            = $updateJobs;
		$this->translationJobFactory = $translationJobFactory;
	}

	public function cancel(): int {
		$jobsInProgress = $this->ateJobsRepository->get_jobs_to_sync();

		foreach ( $jobsInProgress as $jobInProgress ) {
			$jobInProgress->set_status( ICL_TM_NOT_TRANSLATED );
			$this->updateJobs->update_state( $jobInProgress );
			$this->translationJobFactory->delete_job_data( $jobInProgress->get_translate_job_id() );
		}

		return count( $jobsInProgress );
	}
}
