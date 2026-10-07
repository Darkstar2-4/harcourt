<?php

class WPML_TM_TF_Feedback_List_Hooks_Factory extends WPML_Current_Screen_Loader_Factory {

	public function get_screen_regex() {
		return '/wpml-translation-feedback-list/';
	}

	public function create_hooks() {
		return new WPML_TM_TF_Feedback_List_Hooks();
	}
}
