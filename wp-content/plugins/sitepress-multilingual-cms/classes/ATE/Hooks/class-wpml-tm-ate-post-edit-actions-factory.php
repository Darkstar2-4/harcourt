<?php

class WPML_TM_ATE_Post_Edit_Actions_Factory implements IWPML_Backend_Action_Loader {

	public function create() {
		$tm_ate    = new WPML_TM_ATE();
		$endpoints = WPML\Container\make( 'WPML_TM_ATE_AMS_Endpoints' );

		if ( $tm_ate->is_translation_method_ate_enabled() ) {
			return new WPML_TM_ATE_Post_Edit_Actions( $endpoints );
		}

		return null;
	}
}
