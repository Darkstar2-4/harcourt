<?php

namespace WPML\LIB\WP;

use WPML\Collect\Support\Traits\Macroable;
use WPML\FP\Either;
use WPML\FP\Fns;
use WPML\FP\Logic;
use WPML\FP\Lst;
use function WPML\FP\curryN;
use function WPML\FP\gatherArgs;
use function WPML\FP\partialRight;
use function WPML\FP\pipe;

class Post {

	use Macroable;

	public static function init() {

		self::macro( 'getTerms', curryN( 2, pipe(
			'get_the_terms',
			Logic::ifElse( Logic::isArray(), [ Either::class, 'right' ], [ Either::class, 'left' ] )
		) ) );

		self::macro( 'getMetaSingle', curryN( 2, partialRight( 'get_post_meta', true ) ) );

		self::macro( 'updateMeta', curryN( 3, 'update_post_meta' ) );

		self::macro( 'deleteMeta', curryN( 2, 'delete_post_meta' ) );

		self::macro( 'getType', curryN( 1, 'get_post_type' ) );

		self::macro( 'get', curryN( 1, Fns::unary( 'get_post' ) ) );

		self::macro( 'getStatus', curryN( 1, 'get_post_status' ) );

		self::macro( 'update', curryN( 1, Fns::unary( 'wp_update_post' ) ) );

		self::macro( 'insert', curryN( 1, Fns::unary( 'wp_insert_post' ) ) );

		self::macro( 'setStatus', curryN(2, gatherArgs( pipe( Lst::zipObj( [ 'ID', 'post_status' ] ), self::update() ) ) ) );

		self::macro( 'setStatusWithoutFilters', curryN( 2, function ( $id, $newStatus ) {
			global $wpdb;
			$result = $wpdb->update( $wpdb->posts, [ 'post_status' => $newStatus ], [ 'ID' => $id ] ) ? $id : 0;
			if ( $result ) clean_post_cache( $id );
			return $result;
;		} ) );

		self::macro( 'delete', curryN( 1, partialRight( 'wp_delete_post', true ) ) );

		self::macro( 'trash', curryN( 1, partialRight( 'wp_delete_post', false ) ) );
	}
}

Post::init();
