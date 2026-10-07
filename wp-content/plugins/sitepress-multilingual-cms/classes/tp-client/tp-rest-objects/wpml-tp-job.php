<?php

class WPML_TP_Job extends WPML_TP_REST_Object {

	const CANCELLED = 'cancelled';

	private $id;

	private $cms_id;

	private $batch;

	private $job_state;

	public function set_id( $id ) {
		$this->id = (int) $id;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_cms_id() {
		return $this->cms_id;
	}

	public function get_job_state() {
		return $this->job_state;
	}

	public function get_original_element_id() {
		preg_match_all( '/\d+/', $this->get_cms_id(), $matches );
		return isset( $matches[0][0] ) ? (int) $matches[0][0] : null;
	}

	public function get_batch() {
		return $this->batch;
	}

	public function set_cms_id( $id ) {
		$this->cms_id = $id;
	}

	public function set_job_state( $state ) {
		$this->job_state = $state;
	}

	public function set_batch( stdClass $batch ) {
		$this->batch = $batch;
	}

	protected function get_properties() {
		return array(
			'id'        => 'id',
			'batch'     => 'batch',
			'cms_id'    => 'cms_id',
			'job_state' => 'job_state',
		);
	}
}
