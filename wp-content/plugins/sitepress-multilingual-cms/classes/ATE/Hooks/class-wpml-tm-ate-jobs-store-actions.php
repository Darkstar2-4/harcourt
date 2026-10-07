<?php

class WPML_TM_ATE_Jobs_Store_Actions implements IWPML_Action {
	private $ate_jobs;

	public function __construct( WPML_TM_ATE_Jobs $ate_jobs ) {
		$this->ate_jobs = $ate_jobs;
	}

	public function add_hooks() {
		add_action( 'wpml_tm_ate_jobs_store', array( $this, 'store_action' ), 10, 2 );
	}

	public function store( $wpml_job_id, $ate_job_data ) {
		return $this->ate_jobs->store( $wpml_job_id, $ate_job_data );
	}

	public function store_action( $wpml_job_id, $ate_job_data ) {
		$this->ate_jobs->store( $wpml_job_id, $ate_job_data );
	}
}
