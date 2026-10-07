<?php

class WPML_TM_API_Hook_Links implements IWPML_Action {

	public function add_hooks() {
		add_filter(
			'wpml_get_post_translation_settings_link',
			array(
				$this,
				'get_post_translation_settings_link',
			),
			11,
			1
		);
	}

	public function get_post_translation_settings_link( $link ) {
		return admin_url( 'admin.php?page=' . WPML_TM_FOLDER . WPML_Translation_Management::PAGE_SLUG_SETTINGS . '&sm=mcsetup#icl_custom_posts_sync_options' );
	}
}
