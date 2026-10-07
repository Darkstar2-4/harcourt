<?php

class WPML_Attachments_Urls_With_Identical_Slugs_Factory implements IWPML_Frontend_Action_Loader, IWPML_Deferred_Action_Loader {

	public function get_load_action() {
		return 'init';
	}

	public function create() {
		return new WPML_Attachments_Urls_With_Identical_Slugs();
	}

}
