<?php

namespace WPML\BackgroundTask;

use WPML\Core\BackgroundTask\Model\TaskEndpointInterface;
use WPML\Core\BackgroundTask\Model\BackgroundTask;
use function WPML\Container\make;

class BackgroundTaskViewModel {

	public static function get( $backgroundTask, $getLock = false ) {
		$className = $backgroundTask->getTaskType();

		if ( ! class_exists( $className ) ) {
			return;
		}

		$endpointInstance = make( $className );

		$endpointLock = make( 'WPML\Utilities\Lock', [ ':name' => $backgroundTask->getTaskType() ] );
		$hasLock      = $getLock ? $endpointLock->create( $endpointInstance->getLockTime() ) : false;

		return [
			'isPaused'      => $backgroundTask->isStatusPaused(),
			'progressTotal' => $backgroundTask->getTotalCount(),
			'progressDone'  => $backgroundTask->getCompletedCount(),
			'payload'       => $backgroundTask->getPayload(),
			'taskId'        => $backgroundTask->getTaskId(),
			'taskStatus'    => $backgroundTask->getStatusName(),
			'isCompleted'   => $backgroundTask->isStatusCompleted(),
			'description'   => $endpointInstance->getDescription( wpml_collect( $backgroundTask->getPayload() ) ),
			'taskType'      => $backgroundTask->getTaskType(),
			'isDisplayed'   => $endpointInstance->isDisplayed(),
			'hasLock'       => $hasLock,
		];
	}
}
