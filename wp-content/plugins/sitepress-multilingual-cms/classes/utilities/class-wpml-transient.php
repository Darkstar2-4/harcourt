<?php

class WPML_Transient {

	const WPML_TRANSIENT_PREFIX = '_wpml_transient_';

	public function set( $name, $value, $expiration = '' ) {
		$data = array(
			'value'      => $value,
			'expiration' => $expiration ? time() + (int) $expiration : '',
		);

		update_option( self::WPML_TRANSIENT_PREFIX . $name, $data );
	}

	public function get( $name ) {
		$data = get_option( self::WPML_TRANSIENT_PREFIX . $name );

		if ( $data ) {
			if ( (int) $data['expiration'] < time() ) {
				delete_option( self::WPML_TRANSIENT_PREFIX . $name );

				return '';
			}

			return $data['value'];
		}

		return '';
	}

	public function delete( $name ) {
		delete_option( self::WPML_TRANSIENT_PREFIX . $name );
	}
}
