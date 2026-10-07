<?php

namespace WPML\Core\BackgroundTask\Command;

use WPML\Core\BackgroundTask\Exception\TaskNotRunnable\ExceededMaxRetriesException;
use WPML\Core\BackgroundTask\Exception\TaskNotRunnable\TaskIsCompletedException;
use WPML\Core\BackgroundTask\Exception\TaskNotRunnable\TaskIsPausedException;
use WPML\Core\BackgroundTask\Model\BackgroundTask;
use WPML\Core\BackgroundTask\Model\TaskEndpointInterface;

class UpdateBackgroundTask {
	private $wpdb;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function startTask( BackgroundTask $task, TaskEndpointInterface $taskEndpoint ) {
		if ( $task->isStatusPaused() ) {
			throw new TaskIsPausedException();
		}
		if ( $task->isStatusCompleted() ) {
			throw new TaskIsCompletedException();
		}

		if ( $task->isStatusPending() ) {
			return $this->saveStatusStarted( $task );
		}

		if ( $task->isStatusInProgress() ) {
			if ( $taskEndpoint->getMaxRetries() > 0 && $task->getRetryCount() >= $taskEndpoint->getMaxRetries()  ) {
				$this->saveStatusPaused( $task );
				throw new ExceededMaxRetriesException();
			}

			return $this->runRetry( $task );
		}
	}

	public function runUpdate( BackgroundTask $model ) {
		$this->wpdb->update(
			$this->wpdb->prefix . BackgroundTask::TABLE_NAME,
			$model->serialize(),
			[ 'task_id' => $model->getTaskId() ]
		);
	}

	private function saveStatusStarted( BackgroundTask $model ) {
		$model->setStartingDate( new \DateTime() );
		$model->setStatus( BackgroundTask::TASK_STATUS_INPROGRESS );
		$this->runUpdate( $model );
		return $model;
	}

	public function runStop( BackgroundTask $model ) {
		$model->setStatus( BackgroundTask::TASK_STATUS_PAUSED );
		$model->setCompletedCount( 0 );
		$model->setCompletedIds( array() );
		$this->runUpdate( $model );
	}

	public function saveStatusPaused( BackgroundTask $model ) {
		$model->setStatus( BackgroundTask::TASK_STATUS_PAUSED );
		$this->runUpdate( $model );
	}

	public function saveStatusResumed( BackgroundTask $model ) {
		$model->setStatus( BackgroundTask::TASK_STATUS_PENDING );
		$this->runUpdate( $model );
	}

	public function saveStatusRestart( BackgroundTask $model ) {
		$model->setStatus( BackgroundTask::TASK_STATUS_PENDING );
		$model->setCompletedCount( 0 );
		$model->setCompletedIds( array() );
		$this->runUpdate( $model );
	}

	public function runRetry( BackgroundTask $model ) {
		$model->setStartingDate( new \DateTime() );
		$model->setRetryCount( $model->getRetryCount() + 1 );
		$this->runUpdate( $model );
		return $model;
	}
}