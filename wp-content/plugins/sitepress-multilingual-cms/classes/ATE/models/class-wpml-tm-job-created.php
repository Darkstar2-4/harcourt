<?php

class WPML_TM_Job_Created {
	public $job_id;
	public $rid;
	public $translation_service;
	public $translator_id;
	public $translation_package;
	public $batch_options;
	public $data;

	public function __construct( array $args ) {
		foreach ( $args as $key => $value ) {
			$this->$key = $value;
		}
	}

}