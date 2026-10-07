<?php

abstract class WPML_TP_Project_User {

	protected $project;

	public function __construct( &$project ) {
		$this->project = &$project;
	}
}
