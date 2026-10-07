<?php

namespace WPML\PB\Integrations\Divi;

class Helper {

	public static function isRunningDivi5() {
		$theme  = wp_get_theme();
		$parent = $theme->parent() ?: $theme;

		list( $version ) = explode( '-', trim( $parent->get( 'Version' ) ), 2 );

		return version_compare( $version, '5.0', '>=' );
	}

	public static function isPostUsingDivi5( $postId ) {
		return (bool) get_post_meta( $postId, '_et_pb_use_divi_5', true );
	}

	public static function isInDiviBuilder() {
		return function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled();
	}

	public static function isInDiviBuilderMainWindow() {
		return self::isInDiviBuilder() && empty( $_GET['app_window'] );
	}
}
