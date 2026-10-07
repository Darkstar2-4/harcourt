<?php
class WPML_TM_Shortcodes_Catcher_Factory implements IWPML_Frontend_Action_Loader, IWPML_Backend_Action_Loader, IWPML_AJAX_Action_Loader {

	public function create() {
		return new WPML_TM_Shortcodes_Catcher();
	}
}
