<?php

namespace WPML\FP\System;

function getFilterFor( $key ) {
	return new _Filter( $key );
}

function filterVar( $filter ) {
	return function ( $var ) use ( $filter ) {
		return filter_var( $var, $filter );
	};
}

function sanitizeString( $flags = ENT_QUOTES ) {
	return function( $value ) use ( $flags ) {
		return is_string( $value ) || is_numeric( $value )
			? str_replace( '&amp;', '&', htmlspecialchars( strip_tags( $value ), $flags ) ) : false;
	};
}

function getValidatorFor( $key ) {
	return new _Validator( $key );
}

