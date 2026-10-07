<?php

namespace WPML\TM\ATE\AutoTranslate\Endpoint;

use WPML\Ajax\IHandler;
use WPML\Collect\Support\Collection;
use WPML\FP\Either;
use WPML\FP\Fns;
use WPML\LIB\WP\Option;
use WPML\LIB\WP\User;
use WPML\TM\API\ATE\Account;
use WPML\WP\OptionManager;
use function WPML\Container\make;

class GetCredits implements IHandler {

	public function run( Collection $data ) {
		if ( ! User::canManageTranslations() ) {
			return Either::left( 'Insufficient permissions' );
		}

		return Account::getCredits();
	}
}
