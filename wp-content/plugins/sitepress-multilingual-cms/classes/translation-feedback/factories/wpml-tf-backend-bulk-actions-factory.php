<?php

class WPML_TF_Backend_Bulk_Actions_Factory {

	public function create() {
		return new WPML_TF_Backend_Bulk_Actions(
			new WPML_TF_Data_Object_Storage( new WPML_TF_Feedback_Post_Convert() ),
			new WPML_WP_API(),
			new WPML_TF_Backend_Notices()
		);
	}
}
