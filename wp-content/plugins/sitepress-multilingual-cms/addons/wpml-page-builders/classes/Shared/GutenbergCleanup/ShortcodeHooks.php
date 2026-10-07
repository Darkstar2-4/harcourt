<?php

namespace WPML\PB\GutenbergCleanup;

use WPML\FP\Fns;
use WPML\FP\Maybe;
use WPML\FP\Obj;
use WPML\LIB\WP\Gutenberg;
use function WPML\FP\partialRight;
use function WPML\FP\pipe;
use function WPML\FP\tap as tap;

class ShortcodeHooks implements \IWPML_Backend_Action {

	public function add_hooks() {
		add_action(
			'wp_insert_post',
			Fns::withoutRecursion( Fns::noop(), [ $this, 'removeGutenbergFootprint' ] ),
			10, 2
		);
	}

	public function removeGutenbergFootprint( $post_ID, $post ) {
		$isWpPost = partialRight( 'is_a', \WP_Post::class );

		$isBuiltWithShortcodes = function( \WP_Post $post ) {
			return apply_filters( 'wpml_pb_is_post_built_with_shortcodes', false, $post );
		};

		$hasGutenbergMetaData = pipe(
			Obj::prop( 'post_content' ),
			Gutenberg::hasBlock()
		);

		$removeHtmlComments = tap( pipe(
			Obj::over( Obj::lensProp( 'post_content' ), Gutenberg::stripBlockData() ),
			'wp_update_post'
		) );

		$deleteGutenbergPackage = pipe(
			Obj::prop( 'ID' ),
			[ Package::class, 'get' ],
			[ Package::class, 'delete' ]
		);

		Maybe::of( $post )
			->filter( $isWpPost )
			->filter( $isBuiltWithShortcodes )
			->filter( $hasGutenbergMetaData )
			->map( $removeHtmlComments )
			->map( $deleteGutenbergPackage );
	}
}
