<?php

namespace WPML\TM\ATE\Review;

use WPML\FP\Fns;
use WPML\FP\Logic;
use WPML\FP\Lst;
use WPML\FP\Obj;

class NonPublicCPTPreview {

	const POST_TYPE = 'wpmlReviewPostType';

	public static function addArgs( array $args ) {
		return Obj::assoc( self::POST_TYPE, \get_post_type( $args['preview_id'] ), $args );
	}

	public static function allowReviewPostTypeQueryVar() {
		return Lst::append( self::POST_TYPE );
	}

	public static function enforceReviewPostTypeIfSet() {
		return Logic::ifElse(
			Obj::prop( self::POST_TYPE ),
			Obj::renameProp( self::POST_TYPE, 'post_type' ),
			Fns::identity()
		) ;
	}
}
