<?php

namespace WPML\TM\ATE\Sitekey;

use WPML\Ajax\IHandler;
use WPML\Collect\Support\Collection;
use WPML\Core\BackgroundTask\Model\BackgroundTask;
use WPML\FP\Either;
use WPML\BackgroundTask\AbstractTaskEndpoint;
use WPML\Core\BackgroundTask\Model\TaskEndpointInterface;
use WPML\Utilities\Lock;
use function WPML\Container\make;

class Endpoint extends AbstractTaskEndpoint implements IHandler, TaskEndpointInterface {
	const LOCK_TIME = 30;
	const MAX_RETRIES = 0;

	public function isDisplayed() {
		return false;
	}

	public function runBackgroundTask( BackgroundTask $task ) {
		if ( ! make( SitekeyProvider::class )->hasSitekey() ) {
			SitekeyConfirmationFlag::markAsCompleted();
			$task->finish();

			return $task;
		}

		$success = make( SitekeyApiClient::class )->sendSitekey();

		if ( $success ) {
			SitekeyConfirmationFlag::markAsCompleted();
		}

		$task->finish();
		return $task;
	}

	public function getTotalRecords( Collection $data ) {
		return 1;
	}

	public function getDescription( Collection $data ) {
		return __('Initializing AMS credentials.', 'sitepress');
	}
}
