<?php

namespace WPML\TM\Jobs;

use WPML\Collect\Support\Traits\Macroable;
use WPML\FP\Fns;
use WPML\FP\Logic;
use WPML\FP\Lst;
use WPML\FP\Str;
use function WPML\FP\curryN;
use function WPML\FP\pipe;

class FieldId {

	use Macroable;

	const TERM_PREFIX             = 't_';
	const TERM_DESCRIPTION_PREFIX = 'tdesc_';
	const TERM_META_FIELD_PREFIX  = 'tfield-';
	const CUSTOM_FIELD_PREFIX     = 'field-';

	public static function init() {



		self::macro(
			'is_any_term_field',
			Logic::anyPass( [ self::is_a_term(), self::is_a_term_description(), self::is_a_term_meta() ] )
		);

		self::macro(
			'get_term_id',
			curryN(
				1,
				Logic::cond(
					[
						[ self::is_a_term(), Str::sub( Str::len( self::TERM_PREFIX ) ) ],
						[ self::is_a_term_description(), Str::sub( Str::len( self::TERM_DESCRIPTION_PREFIX ) ) ],
						[ Fns::always( true ), pipe( Str::split( '-' ), Lst::last() ) ],
					]
				)
			)
		);

		self::macro( 'forTerm', Str::concat( self::TERM_PREFIX ) );

		self::macro( 'forTermDescription', Str::concat( self::TERM_DESCRIPTION_PREFIX ) );

		self::macro(
			'forTermMeta',
			curryN(
				2,
				function ( $termId, $key ) {
					return self::TERM_META_FIELD_PREFIX . $key . '-' . $termId;
				}
			)
		);

	}

	public static function is_a_term( $maybe_term = null ) {
		return call_user_func_array(
			curryN(
				1,
				function( $maybe_term ) {
					return Str::startsWith( self::TERM_PREFIX, $maybe_term );
				}
			),
			func_get_args()
		);
	}

	public static function is_a_term_description( $maybe_term_description = null ) {
		return call_user_func_array(
			curryN(
				1,
				function( $maybe_term_description ) {
					return Str::startsWith( self::TERM_DESCRIPTION_PREFIX, $maybe_term_description );
				}
			),
			func_get_args()
		);
	}

	public static function is_a_term_meta( $maybe_term_meta = null ) {
		return call_user_func_array(
			curryN(
				1,
				function( $maybe_term_meta ) {
					return Str::startsWith( self::TERM_META_FIELD_PREFIX, $maybe_term_meta );
				}
			),
			func_get_args()
		);
	}

	public static function is_a_custom_field( $maybe_custom_field = null ) {
		return call_user_func_array(
			curryN(
				1,
				function( $maybe_custom_field ) {
					return Str::startsWith( self::CUSTOM_FIELD_PREFIX, $maybe_custom_field );
				}
			),
			func_get_args()
		);
	}

	public static function getTermMetaKey( $termMeta = null ) {
		return call_user_func_array(
			curryN(
				1,
				function( $termMeta ) {
					$getKey = pipe(
						Str::sub( Str::len( self::TERM_META_FIELD_PREFIX ) ),
						Str::split( '-' ),
						Lst::dropLast( 1 ),
						Lst::join( '-' )
					);

					return $getKey( $termMeta );
				}
			),
			func_get_args()
		);
	}
}

FieldId::init();

