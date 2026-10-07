<?php

namespace WPML\PB\AutoUpdate;

class Settings {

	public static function isEnabled() {
		if ( defined( 'WPML_TRANSLATION_AUTO_UPDATE_ENABLED' ) ) {
			return (bool) constant( 'WPML_TRANSLATION_AUTO_UPDATE_ENABLED' );
		}

		return true;
	}
}
