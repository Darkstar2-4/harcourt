<?php

namespace WPML\Compatibility\Divi\Hooks;

use WPML\LIB\WP\Hooks;
use WPML\PB\Integrations\Divi\Helper;

use function WPML\FP\spreadArgs;

class GutenbergUpdate implements \IWPML_Backend_Action {

	public function add_hooks() {
		Hooks::onFilter( 'wpml_pb_is_post_built_with_shortcodes', 10, 2 )
			->then( spreadArgs( [ $this, 'isPostBuiltWithShortcodes' ] ) );
	}

	public static function isPostBuiltWithShortcodes( $builtWithShortcodes, $post ) {
		if ( ! self::isDiviPost( $post->ID ) ) {
			return $builtWithShortcodes;
		}

		if ( Helper::isPostUsingDivi5( $post->ID ) ) {
			return $builtWithShortcodes;
		}

		if ( did_filter( 'divi_framework_portability_import_migrated_post_content' ) ) {
			return $builtWithShortcodes;
		}

		return true;
	}

	private static function isDiviPost( $postId ) {
		return 'on' === get_post_meta( $postId, '_et_pb_use_builder', true );
	}
}
