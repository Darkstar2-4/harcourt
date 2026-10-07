<?php
namespace WPML\BackgroundTask;

use WPML\BackgroundTask\BackgroundTaskLoader;
use WPML\BackgroundTask\BackgroundTaskViewModel;
use WPML\Collect\Support\Collection;
use WPML\Core\BackgroundTask\Exception\TaskIsNotRunnableException;
use WPML\Core\BackgroundTask\Model\TaskEndpointInterface;
use WPML\Core\BackgroundTask\Service\BackgroundTaskService;
use WPML\Core\BackgroundTask\Command\UpdateBackgroundTask;
use WPML\Core\BackgroundTask\Model\BackgroundTask;
use WPML\FP\Either;
use WPML\LIB\WP\User;
use function WPML\Container\make;

abstract class AbstractTaskEndpoint implements TaskEndpointInterface {
	const LOCK_TIME = 2*60;
	const MAX_RETRIES = 0;

	protected $updateBackgroundTask;

	protected $backgroundTaskService;

	public function __construct( UpdateBackgroundTask $updateBackgroundTask, BackgroundTaskService $backgroundTaskService ) {
		$this->updateBackgroundTask     = $updateBackgroundTask;
		$this->backgroundTaskService = $backgroundTaskService;
	}

	public function isValidTask( $task_id ) {
		return true;
	}

	public function isDisplayed() {
		return true;
	}

	public function getLockTime() {
		return static::LOCK_TIME;
	}

	public function getMaxRetries() {
		return static::MAX_RETRIES;
	}

	public function getType() {
		return static::class;
	}

	abstract function runBackgroundTask( BackgroundTask $task );

	public function run(
		Collection $data
	) {
		if ( ! User::canManageTranslations() ) {
			return Either::left( 'Insufficient permissions' );
		}

		try {
			if ( ! isset( $data['taskId'] ) ) {
				throw new TaskIsNotRunnableException();
			}

			$taskId     = $data['taskId'];
			$task       = $this->backgroundTaskService->startByTaskId( $taskId );
			$task       = $this->runBackgroundTask( $task );

			$this->updateBackgroundTask->runUpdate( $task );

			return $this->getResponse( $task );
		} catch ( TaskIsNotRunnableException $e ) {
			return Either::of( [ 'error' => $e->getMessage() ] );
		}
	}

	private function getResponse( BackgroundTask $backgroundTask ) {
		$endpointLock = make( 'WPML\Utilities\Lock', [ ':name' => $backgroundTask->getTaskType() ] );
		$endpointLock->release();

		return Either::of(
			BackgroundTaskViewModel::get( $backgroundTask, true )
		);
	}
}
