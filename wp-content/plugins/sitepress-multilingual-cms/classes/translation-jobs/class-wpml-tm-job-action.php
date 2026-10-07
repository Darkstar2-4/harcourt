<?php

abstract class WPML_TM_Job_Action {

	protected $job_action_factory;


	public function __construct( &$job_action_factory ) {
		$this->job_action_factory = &$job_action_factory;
	}
}
