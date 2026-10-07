<?php

$otg_ui_version = 11000;


global $otg_ui_versions;

if ( ! isset( $otg_ui_versions ) ) {
	$otg_ui_versions = array();
}

if ( ! isset( $otg_ui_versions[ $otg_ui_version ] ) ) {
	$otg_ui_versions[ $otg_ui_version ] = array(
		'path' => wp_normalize_path( __DIR__ ),
	);
}


if ( ! function_exists( 'otgs_ui_initialize' ) ) {

	function otgs_ui_initialize( $vendor_path, $vendor_url ) {
		global $otg_ui_versions;

		if ( is_link( $vendor_path ) ) {
			$vendor_path = readlink( $vendor_path );
		}

		$vendor_path = wp_normalize_path( $vendor_path );
		$vendor_path = untrailingslashit( $vendor_path );
		$vendor_url  = untrailingslashit( $vendor_url );

		foreach ( $otg_ui_versions as $version => $data ) {
			if ( $otg_ui_versions[ $version ]['path'] === $vendor_path ) {
				$otg_ui_versions[ $version ]['url'] = $vendor_url;
				break;
			}
		}
	}
}

if ( ! function_exists( 'otgs_ui_plugins_loaded' ) ) {
	function otgs_ui_plugins_loaded() {
		global $otg_ui_versions;

		$latest = 0;
		foreach ( $otg_ui_versions as $version => $data ) {
			if ( $version > $latest ) {
				$latest = $version;
			}
		}

		if ( $latest > 0 && isset( $otg_ui_versions[ $latest ]['url'] ) ) {
			require_once $otg_ui_versions[ $latest ]['path'] . '/src/php/OTGS_Assets_Handles.php';
			require_once $otg_ui_versions[ $latest ]['path'] . '/src/php/OTGS_Assets_Store.php';
			require_once $otg_ui_versions[ $latest ]['path'] . '/src/php/OTGS_UI_Assets.php';
			require_once $otg_ui_versions[ $latest ]['path'] . '/src/php/OTGS_UI_Loader.php';

			$assets_store = new OTGS_Assets_Store();
			$assets       = new OTGS_UI_Assets( $otg_ui_versions[ $latest ]['url'] . '/dist', $assets_store );
			$loader       = new OTGS_UI_Loader( $assets_store, $assets );
			$loader->load();
		}
	}

	add_action( 'plugins_loaded', 'otgs_ui_plugins_loaded', -PHP_INT_MAX );
}
