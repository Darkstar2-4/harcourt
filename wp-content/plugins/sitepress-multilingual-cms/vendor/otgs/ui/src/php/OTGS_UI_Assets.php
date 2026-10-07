<?php

class OTGS_UI_Assets {
	const ASSETS_TYPES_SCRIPT = 'script';
	const ASSETS_TYPES_STYLE  = 'style';

	private $assets_root_url;

	private $assets_store;

	public function __construct( $assets_root_url, OTGS_Assets_Store $assets_store ) {
		$this->assets_store    = $assets_store;
		$this->assets_root_url = $assets_root_url;
	}

	public function register() {
		$this->register_scripts();
		$this->register_styles();
	}

	private function register_scripts() {
		foreach ( $this->assets_store->get( 'js' ) as $handle => $path ) {
			$this->register_script( $handle, $path );
		}
	}

	private function register_script( $handle, $path ) {
		$this->register_resource( self::ASSETS_TYPES_SCRIPT, $handle, $path );
	}

	private function register_resource( $type, $handle, $path ) {
		$function = null;
		if ( self::ASSETS_TYPES_SCRIPT === $type ) {
			$function = 'wp_register_script';
		}
		if ( self::ASSETS_TYPES_STYLE === $type ) {
			$function = 'wp_register_style';
		}
		if ( ! $function ) {
			return;
		}

		if ( 1 === count( $path ) ) {
			$function( $handle, $this->get_assets_base_url() . '/' . $path[0] );
		} else {
			foreach ( $path as $index => $resource ) {
				$function( $handle . '-' . ( $index + 1 ), $this->get_assets_base_url() . '/' . $resource );
			}
		}
	}

	private function get_assets_base_url() {
		return rtrim( $this->assets_root_url, '/\\' );
	}

	private function register_styles() {
		foreach ( $this->assets_store->get( 'css' ) as $handle => $path ) {
			$this->register_style( $handle, $path );
		}
	}

	private function register_style( $handle, $path ) {
		$this->register_resource( self::ASSETS_TYPES_STYLE, $handle, $path );
	}
}
