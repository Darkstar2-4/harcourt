<?php

namespace WPML\FP\Strings;

use function WPML\FP\partial;
use function WPML\FP\partialRight;
use function WPML\FP\pipe;

function ltrimWith( $trim ) {
	return partialRight( 'ltrim', $trim );
}

function rtrimWith( $trim ) {
	return partialRight( 'rtrim', $trim );
}

function explodeToCollection( $delimiter ) {
	return pipe( partial( 'explode', $delimiter ), 'wpml_collect' );
}

function replace( $search, $replace ) {
	return partial( 'str_replace', $search, $replace );
}

function remove( $remove ) {
	return partial( 'str_replace', $remove, '' );
}

