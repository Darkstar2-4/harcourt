<?php

class WPML_Media_Attachments_Query_Factory implements IWPML_Frontend_Action_Loader, IWPML_Backend_Action_Loader {

	public function create() {
		return new WPML_Media_Attachments_Query();
	}

}
