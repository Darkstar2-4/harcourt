<?php

namespace WPML\TM\ATE\ClonedSites\AutoMigration\Endpoints;

use WPML\Ajax\IHandler;
use WPML\Collect\Support\Collection;
use WPML\FP\Either;
use WPML\LIB\WP\User;
use WPML\TM\ATE\ClonedSites\AutoMigration\Handler;
use WPML\TM\ATE\ClonedSites\AutoMigration\Notice;

class Dismiss implements IHandler {

	public function run( Collection $data ) {
		if ( ! User::canManageOptions() ) {
			return Either::left( 'Insufficient permissions' );
		}

		Handler::clearMigrationData();
		delete_option( Notice::NOTICE_URL_KEY );

		return Either::of( true );
	}
}
