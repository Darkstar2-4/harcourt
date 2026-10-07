<?php


namespace WPML\TM\ATE\AutoTranslate\Endpoint;


use WPML\Ajax\IHandler;
use WPML\Collect\Support\Collection;
use WPML\FP\Either;
use WPML\LIB\WP\User;
use WPML\Utilities\KeyedLock;
use function WPML\Container\make;

class SyncLock implements IHandler {

	public function run( Collection $data ) {
		if ( ! User::isTranslator() && ! User::canManageTranslations() ) {
			return Either::left( 'Insufficient permissions' );
		}

		$lock = make( \WPML\TM\ATE\SyncLock::class );

		$action = $data->get( 'action', 'acquire' );

		if ( $action === 'release' ) {
			$lockKey = $lock->create( $data->get( 'lockKey' ) );
			if ( $lockKey ) {
				$lock->release();
			}

			return Either::of( [ 'action' => 'release', 'result' => (bool) $lockKey ] );
		} else {
			return Either::of( [ 'action' => 'acquire', 'result' => $lock->create( null ) ] );
		}

	}

}