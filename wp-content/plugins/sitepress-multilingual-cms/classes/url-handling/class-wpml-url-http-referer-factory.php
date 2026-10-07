<?php

class WPML_URL_HTTP_Referer_Factory {

	public function create() {
		return new WPML_URL_HTTP_Referer( new WPML_Rest( new WP_Http() ) );
	}
}
