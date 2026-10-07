<?php

namespace WPML\PB\Elementor\AutoConfig;

class Cache {

	const HASH_OPTION_KEY   = 'wpml_elementor_auto_config_hash';
	const CONFIG_OPTION_KEY = 'wpml_elementor_auto_config';

	public function get( $currentHash ) {
		$cachedHash = get_option( self::HASH_OPTION_KEY );

		if ( $cachedHash === $currentHash ) {
			return (array) get_option( self::CONFIG_OPTION_KEY, [] );
		}

		return null;
	}

	public function set( array $config, $hash ) {
		update_option( self::CONFIG_OPTION_KEY, $config, false );
		update_option( self::HASH_OPTION_KEY, $hash, false );
	}

	public function clear() {
		delete_option( self::CONFIG_OPTION_KEY );
		delete_option( self::HASH_OPTION_KEY );
	}

	public function generateHash( array $widgetInstances ) {
		$hashData = [];
		foreach ( $widgetInstances as $widgetType => $widgetInstance ) {
			$controlKeys = array_keys( $widgetInstance->get_controls() );
			sort( $controlKeys );
			$hashData[ $widgetType ] = implode( ',', $controlKeys );
		}
		ksort( $hashData );

		return md5( wp_json_encode( $hashData ) );
	}
}
