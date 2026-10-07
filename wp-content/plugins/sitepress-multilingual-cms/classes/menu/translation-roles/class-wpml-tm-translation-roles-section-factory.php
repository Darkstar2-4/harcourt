<?php

use WPML\TM\Menu\TranslationServices\SectionFactory;

class WPML_TM_Translation_Roles_Section_Factory implements IWPML_TM_Admin_Section_Factory {

	public function create() {
		return new WPML_TM_Translation_Roles_Section( ( new SectionFactory() )->create() );
	}

}
