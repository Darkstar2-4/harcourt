<?php

class WPML_TM_Email_Twig_Template_Factory {

	public function create() {
		$loader = new WPML_Twig_Template_Loader( array( WPML_TM_PATH . '/templates/emails/' ) );
		return $loader->get_template();
	}
}