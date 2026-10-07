<?php

class WPML_TM_Word_Count_Process_Hooks implements IWPML_Action {

	private $process_factory;

	public function __construct( WPML_TM_Word_Count_Background_Process_Factory $process_factory ) {
		$this->process_factory = $process_factory;
	}

	public function add_hooks() {
		$this->process_factory->create_requested_types();
	}
}
