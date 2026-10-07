<?php

if ( ! function_exists( 'otgs_icons_register_assets' ) ) {
	
	function otgs_icons_register_assets( $assets_data, $assets_version ) {
		define( 'OTGS_ASSETS_ICONS_STYLES', 'otgs-icons' );
		
		wp_register_style( OTGS_ASSETS_ICONS_STYLES, $assets_data[ 'url' ] . '/css/otgs-icons.css', array(), $assets_version );
	}

}