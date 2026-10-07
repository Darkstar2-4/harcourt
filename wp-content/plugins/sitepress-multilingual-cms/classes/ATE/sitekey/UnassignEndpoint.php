<?php

namespace WPML\TM\ATE\Sitekey;

use WPML\Ajax\IHandler;
use WPML\Collect\Support\Collection;
use WPML\Core\BackgroundTask\Model\BackgroundTask;
use WPML\FP\Either;
use WPML\BackgroundTask\AbstractTaskEndpoint;
use WPML\Core\BackgroundTask\Model\TaskEndpointInterface;
use function WPML\Container\make;

class UnassignEndpoint extends AbstractTaskEndpoint implements IHandler, TaskEndpointInterface {

	const LOCK_TIME = 30;
	const MAX_RETRIES = 0;

	public function isDisplayed() {
		return false;
	}

	public function runBackgroundTask( BackgroundTask $task ) {
		$sitekey = StoredSitekey::get();

		if ( ! $sitekey ) {
			$task->finish();
			return $task;
		}

		$result = make( UnassignApiClient::class )->unassign( $sitekey );

		if ( $result->isSuccess() || ! $result->isRetryable() ) {
			StoredSitekey::clear();
		}

		$task->finish();
		return $task;
	}

	public function getTotalRecords( Collection $data ) {
		return 1;
	}

	public function getDescription( Collection $data ) {
		return __( 'Unassigning site key from AMS.', 'sitepress' );
	}
}
