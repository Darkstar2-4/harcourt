<?php

namespace WPML\PB\Cornerstone\Hooks;

use WPML\LIB\WP\Hooks;
use function WPML\FP\spreadArgs;

class ShortcodeAttributes implements \IWPML_Frontend_Action {

	public function add_hooks() {
		Hooks::onFilter( 'shortcode_atts_cs_content', 10, 2 )
			->then( spreadArgs( [ self::class, 'restoreContentId' ] ) );
	}

	public static function restoreContentId( $out, $pairs ) {
		if ( isset( $out['_p'], $pairs['_p'] ) ) {
			$out['_p'] = $pairs['_p'];
		}

		return $out;
	}
}
