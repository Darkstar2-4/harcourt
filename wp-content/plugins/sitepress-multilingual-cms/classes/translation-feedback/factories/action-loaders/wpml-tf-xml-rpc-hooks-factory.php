<?php

class WPML_TF_XML_RPC_Hooks_Factory implements IWPML_Frontend_Action_Loader {

	public function create() {
		return new WPML_TF_XML_RPC_Hooks(
			new WPML_TF_XML_RPC_Feedback_Update_Factory(),
			new WPML_WP_API()
		);
	}
}
