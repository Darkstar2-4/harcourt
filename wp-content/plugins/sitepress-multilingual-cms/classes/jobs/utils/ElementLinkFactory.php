<?php

namespace WPML\TM\Jobs\Utils;

use function WPML\Container\make;
use WPML_Post_Translation;

class ElementLinkFactory {

	public static function create() {
		global $wpml_post_translations;

		return make(
			ElementLink::class,
			[ ':postTranslation' => $wpml_post_translations ]
		);
	}
}
