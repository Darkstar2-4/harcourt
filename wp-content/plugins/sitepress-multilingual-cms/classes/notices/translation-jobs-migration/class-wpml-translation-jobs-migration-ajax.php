<?php

class WPML_Translation_Jobs_Migration_Ajax {

	const ACTION = 'wpml_translation_jobs_migration';

	const JOBS_MIGRATED_PER_REQUEST = 100;

	private $jobs_migration;

	private $jobs_repository;

	private $migration_state;

	public function __construct(
		WPML_Translation_Jobs_Migration $jobs_migration,
		WPML_Translation_Jobs_Migration_Repository $jobs_repository,
		WPML_TM_Jobs_Migration_State $migration_state
	) {
		$this->jobs_migration  = $jobs_migration;
		$this->jobs_repository = $jobs_repository;
		$this->migration_state = $migration_state;
	}

	public function run_migration() {
		if ( ! $this->is_valid_request() ) {
			wp_send_json_error();
			return;
		}

		$jobs = $this->jobs_repository->get();

		$jobs_chunk = array_slice( $jobs, 0, self::JOBS_MIGRATED_PER_REQUEST );
		try {
			$this->jobs_migration->migrate_jobs( $jobs_chunk );
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage(), 500 );

			return;
		}

		$done             = count( $jobs ) === count( $jobs_chunk );
		$total_jobs       = count( $jobs );
		$jobs_chunk_total = count( $jobs_chunk );

		$result = array(
			'totalJobs'    => $total_jobs,
			'jobsMigrated' => $jobs_chunk_total,
			'done'         => $done,
		);

		if ( $jobs_chunk_total === $total_jobs ) {
			$this->migration_state->mark_migration_as_done();
		}

		wp_send_json_success( $result );
	}

	private function is_valid_request() {
		return wp_verify_nonce( $_POST['nonce'], self::ACTION );
	}
}
