<?php

class WPML_TP_Job_Status {
	private $tp_id;

	private $batch_id;

	private $status;

	private $revision;

	private $ts_status;

	public function __construct( $tp_id, $batch_id, $state, $revision = 1, $ts_status = null ) {
		$this->tp_id    = (int) $tp_id;
		$this->batch_id = (int) $batch_id;

		if ( ! in_array( $state, WPML_TP_Job_States::get_possible_states(), true ) ) {
			$state = 'any';
		}
		$this->status    = $state;
		$this->revision  = (int) $revision;
		$this->ts_status = $ts_status;
	}

	public function get_tp_id() {
		return $this->tp_id;
	}

	public function get_batch_id() {
		return $this->batch_id;
	}

	public function get_status() {
		return $this->status;
	}

	public function get_revision() {
		return $this->revision;
	}

	public function get_ts_status() {
		return $this->ts_status;
	}

}
