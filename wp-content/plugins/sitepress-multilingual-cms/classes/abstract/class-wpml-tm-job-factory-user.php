<?php

abstract class WPML_TM_Job_Factory_User {

	protected $job_factory;

	public function __construct( $job_factory ) {
		$this->job_factory = $job_factory;
	}
}
