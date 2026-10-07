<?php

class WPML_REST_Extend_Args_Factory implements IWPML_REST_Action_Loader {
	public function create() {
		global $sitepress;

		return new WPML_REST_Extend_Args( $sitepress );
	}
}
