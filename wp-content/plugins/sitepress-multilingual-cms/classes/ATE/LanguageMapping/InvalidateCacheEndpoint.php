<?php

namespace WPML\TM\ATE\LanguageMapping;

use WPML\Ajax\IHandler;
use WPML\Collect\Support\Collection;
use WPML\FP\Either;
use WPML\LIB\WP\User;
use WPML\TM\API\ATE\CachedLanguageMappings;

class InvalidateCacheEndpoint implements IHandler {
	public function run( Collection $data ) {
		if ( ! User::canManageTranslations() ) {
			return Either::left( 'Insufficient permissions' );
		}

		CachedLanguageMappings::clearCache();
		return Either::of( true );
	}
}
