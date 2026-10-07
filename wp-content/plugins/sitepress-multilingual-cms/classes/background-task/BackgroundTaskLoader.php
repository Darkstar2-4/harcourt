<?php

namespace WPML\BackgroundTask;

use WPML\Collect\Support\Collection;
use WPML\Core\BackgroundTask\Command\UpdateBackgroundTask;
use WPML\Core\BackgroundTask\Repository\BackgroundTaskRepository;
use WPML\Core\WP\App\Resources;
use WPML\FP\Either;
use WPML\FP\Fns;
use WPML\FP\Lst;
use WPML\LIB\WP\Hooks;
use WPML\LIB\WP\User;

class BackgroundTaskLoader implements \IWPML_Backend_Action, \IWPML_DIC_Action {

	private $updateBackgroundTaskCommand;

	private $backgroundTaskRepository;

	public function __construct(
		UpdateBackgroundTask $updateBackgroundTaskCommand,
		BackgroundTaskRepository $backgroundTaskRepository
	) {
		$this->updateBackgroundTaskCommand     = $updateBackgroundTaskCommand;
		$this->backgroundTaskRepository = $backgroundTaskRepository;
	}


	public function add_hooks() {
		Hooks::onAction( 'wp_loaded' )
		     ->then( [ $this, 'enqueueTasksRegistry' ] );
	}

	public function enqueueTasksRegistry() {
		if ( ! User::canManageTranslations() ) {
			return;
		}

		$tasks = $this->getSerializedTasks();
		Resources::enqueueGlobalVariable(
			'wpml_background_tasks',
			[
				'endpoints' => array_merge( Lst::pluck( 'taskType', $tasks ), [ self::class ] ),
				'tasks'     => $tasks,
			]
		);
	}

	public function run(
		Collection $data
	) {
		if ( ! User::canManageTranslations() ) {
			return Either::left( 'Insufficient permissions' );
		}

		$taskId = isset( $data['taskId'] ) ? $data['taskId'] : null;
		$cmd    = isset( $data['cmd'] ) ? $data['cmd'] : null;

		if ( ! $taskId ) {
			return [];
		}

		$task = $this->backgroundTaskRepository->getByTaskId( $taskId );

		if ( ! $task ) {
			return Either::of( null );
		} elseif ( 'stop' === $cmd ) {
			$this->updateBackgroundTaskCommand->runStop( $task );
		} elseif ( 'pause' === $cmd ) {
			$this->updateBackgroundTaskCommand->saveStatusPaused( $task );
		} elseif ( 'resume' === $cmd ) {
			$this->updateBackgroundTaskCommand->saveStatusResumed( $task );
		} elseif ( 'restart' === $cmd ) {
			$this->updateBackgroundTaskCommand->saveStatusRestart( $task );
		}

		$taskData = BackgroundTaskViewModel::get( $task );

		return Either::of( $taskData );
	}


	public function getSerializedTasks() {
		return Fns::map(
			function( $task ) {
				return BackgroundTaskViewModel::get( $task, true );
			},
			$this->backgroundTaskRepository->getAllRunnableTasks()
		);
	}


}
