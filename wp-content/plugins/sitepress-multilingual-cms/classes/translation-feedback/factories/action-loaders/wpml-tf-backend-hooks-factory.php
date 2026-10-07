<?php

class WPML_TF_Backend_Hooks_Factory implements IWPML_Backend_Action_Loader {

	public function create() {
		global $wpdb;

		return new WPML_TF_Backend_Hooks(
			new WPML_TF_Backend_Bulk_Actions_Factory(),
			new WPML_TF_Backend_Feedback_List_View_Factory(),
			new WPML_TF_Backend_Styles(),
			new WPML_TF_Backend_Scripts(),
			$wpdb
		);
	}
}
