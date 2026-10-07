<?php

namespace WPML\Convert;

use WPML\FP\Fns;
use WPML\FP\Lens;
use WPML\FP\Lst;
use WPML\FP\Obj;
use WPML\FP\Str;
use WPML\FP\Type;
use WPML\Media\Option;
use function WPML\FP\compose;
use function WPML\FP\pipe;

class Ids {

	const ANY_POST = 'any_post';
	const ANY_TERM = 'any_term';

	public static function convert( $ids, $elementType = null, $fallbackToOriginal = false, $targetLang = null ) {
		$isId = function( $id ) {
			return is_numeric( $id ) && ! is_float( $id );
		};

		$getElementType = self::selectGetElementType( $elementType );

		$convertId = function( $id ) use ( $isId, $getElementType, $fallbackToOriginal, $targetLang ) {
			global $sitepress;

			if ( ! $isId( $id ) ) {
				return $id;
			}

			$elementTypeFamily = $getElementType( (int) $id );

			if ( 'attachment' === $elementTypeFamily && Option::shouldHandleMediaAuto() ) {
				$fallbackToOriginal = true;
			}

			$convertedId = $sitepress->get_object_id( $id, $elementTypeFamily, $fallbackToOriginal, $targetLang );

			if ( $convertedId ) {
				return is_string( $id ) ? (string) $convertedId : $convertedId;
			}

			return null;
		};

		try {
			return $isId( $ids )
				? $convertId( $ids )
				: Obj::over( self::selectLens( $ids ), $convertId, $ids );
		} catch ( \Exception $e ) {
			return $ids;
		}
	}

	private static function selectGetElementType( $elementType ) {
		$memorize = function( $getElementType ) {
			return Fns::memorizeWith( Fns::always( 'same' ), $getElementType );
		};

		if ( self::ANY_POST === $elementType ) {
			return $memorize( 'get_post_type' );
		} elseif ( self::ANY_TERM === $elementType ) {
			return $memorize( pipe( 'get_term', Obj::prop( 'taxonomy' ) ) );
		}

		return Fns::always( $elementType );
	}

	private static function selectLens( $ids ) {
		$getLensFilteredMapped = function() {
			return compose( Lens::iso( 'array_filter', 'array_filter' ), Obj::lensMapped() );
		};

		if ( is_array( $ids ) ) {
			return $getLensFilteredMapped();
		} elseif ( Type::isSerialized( $ids ) ) {
			return compose( Lens::isoUnserialized(), $getLensFilteredMapped() );
		} elseif ( Type::isJson( $ids ) ) {
			return compose( Lens::isoJsonDecoded(), $getLensFilteredMapped() );
		} elseif ( is_string( $ids ) && $glue = self::guessGlue( $ids ) ) {
			return compose( Lens::iso( Str::split( $glue ), Lst::join( $glue ) ), $getLensFilteredMapped() );
		}

		return Lens::iso( Fns::always( $ids ), Fns::always( $ids ) );
	}

	public static function guessGlue( $string ) {
		preg_match_all( '/[^0-9]+/', $string, $matches );
		$uniqueGlues = array_unique( $matches[0] );

		if ( count( $uniqueGlues ) !== 1 ) {
			return false;
		}

		$glue = $uniqueGlues[0];

		if ( $glue === $string ) {
			return false;
		}

		return $glue;
	}
}
