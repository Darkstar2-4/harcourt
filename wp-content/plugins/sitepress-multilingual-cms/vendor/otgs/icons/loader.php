<?php

if ( ! isset( $vendor_root_url ) ) {
	return;
}

$otg_icons_version = 108;


global $otg_icons_versions;
if ( ! isset( $otg_icons_versions ) ) {
    $otg_icons_versions = array();
}
$otg_icons_versions[ $otg_icons_version ] = array(
	'url' => $vendor_root_url . '/otgs/icons',
	'path' => dirname( __FILE__ )
);

if ( ! has_action( 'init', 'otgs_icons_register' ) ) {
	add_action( 'init', 'otgs_icons_register' );
}

if ( ! function_exists( 'otgs_icons_register' ) ) {
	function otgs_icons_register() {
		global $otg_icons_versions;
		$latest = 0;

		foreach ( $otg_icons_versions as $version => $root_utl ) {
			if ( $version > $latest ) {
				$latest = $version;
			}
		}

		require_once( $otg_icons_versions[ $latest ]['path'] . '/register.php' );
        otgs_icons_register_assets( $otg_icons_versions[ $latest ], $latest );
	}
}
