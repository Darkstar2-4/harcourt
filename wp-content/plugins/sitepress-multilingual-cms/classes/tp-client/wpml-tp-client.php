<?php

class WPML_TP_Client {

	private $project;

	private $tm_jobs;

	private $services;

	private $batches;

	private $ratings;

	private $feedback;

	public function __construct(
		WPML_TP_Project $project,
		WPML_TP_TM_Jobs $tm_jobs
	) {
		$this->project = $project;
		$this->tm_jobs = $tm_jobs;
	}

	public function services() {
		if ( ! $this->services ) {
			$this->services = new WPML_TP_API_Services( $this );
		}

		return $this->services;
	}

	public function batches() {
		if ( ! $this->batches ) {
			$this->batches = new WPML_TP_API_Batches( $this );
		}

		return $this->batches;
	}

	public function ratings() {
		if ( ! $this->ratings ) {
			$this->ratings = new WPML_TP_API_TF_Ratings( $this );
		}

		return $this->ratings;
	}

	public function feedback() {
		if ( ! $this->feedback ) {
			$this->feedback = new WPML_TP_API_TF_Feedback( $this );
		}

		return $this->feedback;
	}

	public function get_project() {
		return $this->project;
	}

	public function get_tm_jobs() {
		return $this->tm_jobs;
	}
}
