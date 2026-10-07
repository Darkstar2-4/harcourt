<?php

namespace Smush\Core\Modules;

use Smush\Core\Membership\Membership;

class Smush_Pro extends Smush {
	public function show_warning() {
		if ( ! Membership::get_instance()->is_pro() ) {
			return false;
		}

		if ( ! isset( $this->api_headers ) ) {
			return false;
		}

		if ( isset( $this->api_headers['is_premium'] ) && ! (int)$this->api_headers['is_premium'] ) {
			return true;
		}

		return false;
	}

	protected function get_api_key_headers() {
		$api_key = $this->settings->get_api_key();
		if ( ! empty( $api_key ) ) {
			return array( 'apikey' => $api_key );
		}
		return array();
	}
}