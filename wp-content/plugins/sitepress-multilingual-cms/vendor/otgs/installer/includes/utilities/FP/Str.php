<?php

namespace OTGS\Installer\FP;

use OTGS\Installer\Collect\Support\Macroable;

class Str {
	use Macroable;

	public static function init() {
		self::macro( 'len', curryN( 1, function_exists( 'mb_strlen' ) ? 'mb_strlen' : 'strlen' ) );
	}
}

Str::init();