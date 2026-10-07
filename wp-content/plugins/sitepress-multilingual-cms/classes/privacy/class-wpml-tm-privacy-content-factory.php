<?php

class WPML_TM_Privacy_Content_Factory implements IWPML_Backend_Action_Loader {

	public function create() {
		if ( class_exists( 'WPML_Privacy_Content' ) ) {
			return new WPML_TM_Privacy_Content();
		}

		return null;
	}
}
