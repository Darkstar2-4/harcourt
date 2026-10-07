<?php

class WPML_TP_Services {
	public function get_current_project() {
		return TranslationProxy::get_current_project();
	}

	public function get_current_service() {
		return TranslationProxy::get_current_service();
	}

	public function select_service( $service_id, $custom_fields = false ) {
		TranslationProxy::select_service( $service_id, $custom_fields );
	}
}
