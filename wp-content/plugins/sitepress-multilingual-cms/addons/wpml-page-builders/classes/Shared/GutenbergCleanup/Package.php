<?php

namespace WPML\PB\GutenbergCleanup;

use WPML\FP\Relation;

class Package {

	public static function get( $postId ) {
		$isGbPackage = Relation::propEq( 'kind_slug', 'gutenberg' );

		return wpml_collect( apply_filters( 'wpml_st_get_post_string_packages', [], $postId ) )
			->filter( $isGbPackage )
			->first();
	}

	public static function delete( $package ) {
		if ( $package ) {
			do_action( 'wpml_delete_package', $package->name, $package->kind );
		}
	}
}
