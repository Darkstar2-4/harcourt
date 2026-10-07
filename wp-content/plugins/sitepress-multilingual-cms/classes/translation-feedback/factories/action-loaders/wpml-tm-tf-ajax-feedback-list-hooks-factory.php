<?php

class WPML_TM_TF_AJAX_Feedback_List_Hooks_Factory implements IWPML_AJAX_Action_Loader {

	public function create() {
		return new WPML_TM_TF_Feedback_List_Hooks();
	}
}
