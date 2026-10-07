<?php

class WPML_TM_ATE_Models_Language {
	public $code;
	public $name;

	public function __construct( $code = null, $name = null ) {
		$this->code = $code;
		$this->name = $name;
	}


}