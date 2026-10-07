<?php

class WPML_TM_ATE_Models_Job_File {
	public $content;
	public $name;
	public $type;

	public function __construct( array $args = array() ) {
		foreach ( $args as $key => $value ) {
			$this->$key = $value;
		}
	}
}