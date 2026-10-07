<?php

namespace WPML\TM\ATE\Sitekey;

use WPML\WP\OptionManager;

class SitekeyConfirmationFlag {

	public static function markAsPending() {
		OptionManager::update( 'TM-has-run', Sync::class, false );
	}

	public static function markAsCompleted() {
		OptionManager::update( 'TM-has-run', Sync::class, true );
	}

	public static function isCompleted(): bool {
		return OptionManager::getOr( false, 'TM-has-run', Sync::class );
	}
}
