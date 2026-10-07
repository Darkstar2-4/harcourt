<?php

class WPML_User extends WP_User {

	public function get_meta( $key = '', $single = false ) {
		return get_user_meta( $this->ID, $key, $single );
	}

	public function update_meta( $key, $value, $prev_value = '' ) {
		update_user_meta( $this->ID, $key, $value, $prev_value );
	}

	public function get_option( $option ) {
		return get_user_option( $option, $this->ID );
	}

	function update_option( $option_name, $new_value, $global = false ) {
		return update_user_option( $this->ID, $option_name, $new_value, $global );
	}
}
