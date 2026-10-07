<?php

class WPML_TM_MCS_Pagination_Ajax_Factory implements IWPML_AJAX_Action_Loader {

	public function create() {
		return new WPML_TM_MCS_Pagination_Ajax( new WPML_TM_MCS_Custom_Field_Settings_Menu_Factory() );
	}
}
