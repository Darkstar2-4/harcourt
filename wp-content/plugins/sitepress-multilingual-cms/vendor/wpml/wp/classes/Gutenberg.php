<?php

namespace WPML\LIB\WP;

use WPML\FP\Curryable;
use WPML\FP\Logic;
use WPML\FP\Str;

class Gutenberg {
	use Curryable;

	const GUTENBERG_OPENING_START = '<!-- wp:';

	public static function init() {
		self::curryN( 'hasBlock', 1, Str::includes( self::GUTENBERG_OPENING_START ) );
		self::curryN( 'doesNotHaveBlock', 1, Logic::complement( self::hasBlock() ) );
		self::curryN( 'stripBlockData', 1, Str::pregReplace( '(<!--\s*/?wp:[^<]*-->)', '' ) );
	}

}

Gutenberg::init();
