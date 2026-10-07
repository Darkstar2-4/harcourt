<?php

class WPML_TM_TF_Feedback_List_Hooks implements IWPML_Action {

	public function add_hooks() {
		add_filter( 'wpml_use_tm_editor', [ $this, 'maybe_force_to_use_translation_editor' ], 1, 1 );
	}

	public function maybe_force_to_use_translation_editor( $use_translation_editor ) {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$use_translation_editor = true;
		}

		return $use_translation_editor;
	}
}
