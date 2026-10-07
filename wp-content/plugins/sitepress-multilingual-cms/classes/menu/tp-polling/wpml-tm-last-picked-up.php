<?php

class WPML_TM_Last_Picked_Up {

	private $sitepress;

	public function __construct( $sitepress ) {
		$this->sitepress = $sitepress;
	}

	public function get() {
		return $this->sitepress->get_setting( 'last_picked_up' );
	}

	public function get_formatted( $format = 'Y, F jS @g:i a' ) {
		$last_picked_up      = $this->get();
		$last_time_picked_up = ! empty( $last_picked_up ) ?
			date_i18n( $format, $last_picked_up ) :
			__( 'never', 'wpml-translation-management' );

		return $last_time_picked_up;
	}

	public function set() {
		$this->sitepress->set_setting( 'last_picked_up', current_time( 'timestamp', true ), true );
	}
}
