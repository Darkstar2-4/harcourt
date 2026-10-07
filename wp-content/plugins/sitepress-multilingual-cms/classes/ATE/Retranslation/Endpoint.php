<?php

namespace WPML\TM\ATE\Retranslation;

use WPML\Ajax\IHandler;
use WPML\Collect\Support\Collection;
use WPML\FP\Either;
use WPML\LIB\WP\User;
use WPML\TM\ATE\SyncLock;

class Endpoint implements IHandler {
	private $singlePageBatchHandler;

	private $scheduler;

	private $syncLock;

	public function __construct( SinglePageBatchHandler $singlePageBatchHandler, Scheduler $scheduler, SyncLock $syncLock ) {
		$this->singlePageBatchHandler = $singlePageBatchHandler;
		$this->scheduler              = $scheduler;
		$this->syncLock               = $syncLock;
	}

	public function run( Collection $data ) {
		if ( ! User::canManageTranslations() ) {
			return Either::left( 'Insufficient permissions' );
		}

		if ( $data->get( 'firstSchedule', false ) ) {
			$this->scheduler->scheduleNextRun();

			return Either::of( [] );
		}

		$lockKey = $this->syncLock->create( $data->get( 'lockKey' ) );
		if ( ! $lockKey ) {
			$this->scheduler->scheduleNextRun();
			return Either::left( [ 'lockKey' => false, 'nextPage' => 0 ] );
		}

		$page = $data->get( 'page', 1 );

		$singlePageBatchHandlerResult = $this->singlePageBatchHandler->handle( $page );

		switch ( $singlePageBatchHandlerResult['state'] ) {
			case SinglePageBatchHandler::NOT_FINISHED_IN_ATE:
				$this->scheduler->scheduleNextRun();
				$this->syncLock->release();
				$lockKey = false;
				break;
			case SinglePageBatchHandler::FINISHED_IN_WPML:
				$this->scheduler->disable();
				$this->syncLock->release();
				$lockKey = false;
				break;
		}

		return Either::of( [ 'lockKey' => $lockKey, 'nextPage' => $singlePageBatchHandlerResult['nextPage'] ] );
	}
}
