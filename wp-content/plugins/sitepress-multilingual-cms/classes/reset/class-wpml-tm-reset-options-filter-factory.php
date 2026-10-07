<?php

class WPML_TM_Reset_Options_Filter_Factory implements IWPML_Backend_Action_Loader {

	public function create() {

		return new WPML_TM_Reset_Options_Filter();
	}
}
