<?php

class WPML_Media_Set_Posts_Media_Flag_Factory implements IWPML_Backend_Action_Loader {

	public function create() {
		global $wpdb;

		if (
			!class_exists( 'WPML_Media_Usage_Factory' )
		) {
			return new class {
				public function add_hooks() {
				}
				public function clear_flags() {
					// Required if media translation was updated before sitepress and sitepress still calls
					// the class from Media Translation that was moved to the sitepress core plugin.
				}
				public function clear_flags_action() {
				}
				public function process_batch_action() {
				}
				public function process_batch( $offset ) {
				}
				public function update_post_flag( $post_id ) {
				}
			};
		}

		$post_media_usage_factory = new WPML_Media_Post_Media_Usage_Factory();

		return new WPML_Media_Set_Posts_Media_Flag(
			$wpdb,
			wpml_get_admin_notices(),
			$post_media_usage_factory->create(),
			new WPML_Media_Post_With_Media_Files_Factory()
		);
	}

}