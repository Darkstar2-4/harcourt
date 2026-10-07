<?php

namespace WPML\Core\BackgroundTask\Service;


use WPML\Collect\Support\Collection;
use WPML\Core\BackgroundTask\Command\PersistBackgroundTask;
use WPML\Core\BackgroundTask\Command\UpdateBackgroundTask;
use WPML\Core\BackgroundTask\Command\DeleteBackgroundTask;
use WPML\Core\BackgroundTask\Exception\TaskIsNotRunnableException;
use WPML\Core\BackgroundTask\Model\BackgroundTask;
use WPML\Core\BackgroundTask\Repository\BackgroundTaskRepository;
use WPML\Core\BackgroundTask\Model\TaskEndpointInterface;
use function WPML\Container\make;

class BackgroundTaskService {

	private $backgroundTaskRepository;

	private $persistBackgroundTaskCommand;

	private $updateBackgroundTaskCommand;

	private $deleteBackgroundTaskCommand;

	public function __construct(
		BackgroundTaskRepository $backgroundTaskRepository,
		PersistBackgroundTask $persistBackgroundTaskCommand,
		UpdateBackgroundTask $updateBackgroundTaskCommand,
		DeleteBackgroundTask $deleteBackgroundTaskCommand
	) {
		$this->backgroundTaskRepository     = $backgroundTaskRepository;
		$this->persistBackgroundTaskCommand = $persistBackgroundTaskCommand;
		$this->updateBackgroundTaskCommand = $updateBackgroundTaskCommand;
		$this->deleteBackgroundTaskCommand = $deleteBackgroundTaskCommand;
	}

	public function startByTaskId( $taskId ) {
		$task = $this->backgroundTaskRepository->getByTaskId( $taskId );
		if ( ! $task ) {
			throw new TaskIsNotRunnableException();
		}

		$taskEndpoint = make( $task->getTaskType() );
		if ( ! $taskEndpoint instanceof TaskEndpointInterface ) {
			throw new TaskIsNotRunnableException();
		}
		$task = $this->updateBackgroundTaskCommand->startTask( $task, $taskEndpoint );

		return $task;
	}

	public function addOnce( TaskEndpointInterface $taskEndpoint, Collection $payload ) {
		$backgroundTask = $this->backgroundTaskRepository->getLastIncompletedByType( $taskEndpoint->getType() );
		if ( null === $backgroundTask ) {
			$backgroundTask = $this->add( $taskEndpoint, $payload );
		}

		return $backgroundTask;
	}

	public function add( TaskEndpointInterface $taskEndpoint, Collection $payload ) {
		$payloadArray = $payload->toArray();

		$backgroundTask = $this->backgroundTaskRepository->getLastIncompletedByType(
			$taskEndpoint->getType(),
			$payloadArray
		);

		if ( $backgroundTask ) {
		  $backgroundTask->setCompletedCount( 0 );
		  $backgroundTask->setCompletedIds( null );
		  $this->updateBackgroundTaskCommand->runUpdate( $backgroundTask );
		  return $backgroundTask;
		}

		$itemsCount = $taskEndpoint->getTotalRecords( $payload );
		if ( $itemsCount <= 0 ) {
			return null;
		}
		return $this->persistBackgroundTaskCommand->run(
			$taskEndpoint->getType(),
			BackgroundTask::TASK_STATUS_PENDING,
			$itemsCount,
			$payloadArray,
			[]
		);
	}

	public function delete( $taskId ) {
		$this->deleteBackgroundTaskCommand->run( $taskId );
	}

}
