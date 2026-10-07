<?php

class OTGS_UI_Loader {

	private $assets;

	private $store;

	public function __construct( $locator = null, $assets = null ) {
		if (
			! ( $locator instanceof OTGS_Assets_Store )
			|| ! ( $assets instanceof OTGS_UI_Assets )
		) {
			throw new InvalidArgumentException( 'Missing assets and assets store' );
		}

		$this->store  = $locator;
		$this->assets = $assets;
	}

	public function load() {
		add_action( 'init', array( $this, 'register' ), 1 );
	}

	public function register() {
		$this->store->add_assets_location( __DIR__ . '/../../dist/assets.json' );
		$this->assets->register();
	}
}
