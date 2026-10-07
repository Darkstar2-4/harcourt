<?php

class WPML_TF_TP_Ratings_Synchronize_Factory {

	public function create() {
		$tp_client_factory = new WPML_TP_Client_Factory();
		$tp_client         = $tp_client_factory->create();

		return new WPML_TF_TP_Ratings_Synchronize(
			new WPML_TF_Data_Object_Storage( new WPML_TF_Feedback_Post_Convert() ),
			$tp_client->ratings()
		);
	}
}
