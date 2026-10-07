<?php

namespace WPML\TM\ATE\ClonedSites\AutoMigration\Endpoints;

use WPML\Ajax\IHandler;
use WPML\Collect\Support\Collection;
use WPML\FP\Either;
use WPML\LIB\WP\User;
use WPML\TM\ATE\ClonedSites\AutoMigration\Handler;
use WPML\TM\ATE\ClonedSites\MigrationLogger;
use function WPML\Container\make;

class Connect implements IHandler {

	public function run( Collection $data ) {
		if ( ! User::canManageOptions() ) {
			return Either::left( 'Insufficient permissions' );
		}

		MigrationLogger::beginClonedSiteAction( 'connect' );

		try {
			$amsApi = make( \WPML_TM_AMS_API::class );

			MigrationLogger::connectRequestSent();
			$result = $amsApi->connect();
			MigrationLogger::connectResponse( $result );

			if ( is_wp_error( $result ) && (int) $result->get_error_code() === 409 ) {
				MigrationLogger::connectAlreadyConnected();
				$this->finalize();
				return Either::of( [ 'already_connected' => true ] );
			}

			if ( $result === null || is_wp_error( $result ) ) {
				return Either::left( 'Connect failed' );
			}

			$this->finalize();

			return Either::of( true );
		} finally {
			MigrationLogger::end();
		}
	}

	private function finalize() {
		$hasSitekey = Handler::hasSitekey();
		if ( $hasSitekey ) {
			Handler::clearMigrationData();
		} else {
			Handler::setOrganizationConnected( true );
		}
		MigrationLogger::clonedSiteActionFinalized( $hasSitekey, true );
	}
}
