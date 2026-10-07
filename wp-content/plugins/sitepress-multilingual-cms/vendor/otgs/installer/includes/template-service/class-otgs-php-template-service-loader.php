<?php

class OTGS_Php_Template_Service_Loader implements OTGS_Template_Service_Loader {
	private $template_dir;

	public function __construct( $template_dir ) {
		$this->template_dir = $template_dir;
	}

	public function get_service() {
		return new OTGS_Php_Template_Service( $this->template_dir );
	}
}