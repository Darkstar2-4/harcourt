<?php

namespace WPML\TM\ATE\ClonedSites\AutoMigration\Endpoints;

use WPML\Ajax\IHandler;
use WPML\Collect\Support\Collection;
use WPML\FP\Either;
use WPML\LIB\WP\User;
use WPML\TM\ATE\ClonedSites\AutoMigration\Handler;
use WPML\TM\ATE\ClonedSites\Lock;
use function WPML\Container\make;

class Retry implements IHandler {

	public function run( Collection $data ) {
		if ( ! User::canManageOptions() ) {
			return Either::left( 'Insufficient permissions' );
		}

		$handler = make( Handler::class );

		if ( ! $handler->tryMigrate() ) {
			return Either::left( 'Retry failed' );
		}

		$lock = make( Lock::class );
		$lock->unlock();

		$migrationData = Handler::getMigrationData();

		return Either::of( [
			'initialState'  => Handler::resolveInitialState( $migrationData ),
			'migrationData' => $migrationData,
			'hasSitekey'    => self::hasSitekey(),
		] );
	}

	private static function hasSitekey(): bool {
		if ( ! function_exists( 'OTGS_Installer' ) ) {
			return false;
		}

		$installer = \OTGS_Installer();
		if ( ! $installer ) {
			return false;
		}

		return (bool) $installer->get_site_key( 'wpml' );
	}
}
