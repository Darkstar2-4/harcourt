<?php

namespace WPML\Element\API;

use WPML\FP\Fns;
use WPML\FP\Obj;
use function WPML\FP\curryN;
use function WPML\FP\pipe;

class IfOriginalPost {

	public static function getTranslations( $id = null ) {
		$get = pipe( PostTranslations::getIfOriginal(), Fns::reject( Obj::prop( 'original' ) ), 'wpml_collect' );

		return call_user_func_array( curryN( 1, $get ), func_get_args() );
	}

	public static function getTranslationIds( $id = null ) {
		$get = pipe( self::getTranslations(), Fns::map( Obj::prop( 'element_id' ) ) );

		return call_user_func_array( curryN( 1, $get ), func_get_args() );
	}
}

