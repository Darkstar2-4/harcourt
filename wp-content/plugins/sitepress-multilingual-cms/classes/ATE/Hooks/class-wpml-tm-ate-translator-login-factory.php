<?php

class WPML_TM_ATE_Translator_Login_Factory implements IWPML_Frontend_Action_Loader {

	public function create() {
		if ( WPML_TM_ATE_Status::is_enabled_and_activated() ) {
			return WPML\Container\make( WPML_TM_ATE_Translator_Login::class );
		}

		return null;
	}

}
