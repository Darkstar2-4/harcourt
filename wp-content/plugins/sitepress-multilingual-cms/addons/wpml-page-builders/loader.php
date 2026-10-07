<?php

$wpml_page_builders_version = 36;

add_action(
	'init',
	function () use ( $wpml_page_builders_version ) {
		if ( defined( 'WPML_PAGE_BUILDERS_LOADED' ) ) {
			return;
		}

		define( 'WPML_PAGE_BUILDERS_LOADED', $wpml_page_builders_version );

		require_once __DIR__ . '/app.php';
	},
	1 - $wpml_page_builders_version
);
