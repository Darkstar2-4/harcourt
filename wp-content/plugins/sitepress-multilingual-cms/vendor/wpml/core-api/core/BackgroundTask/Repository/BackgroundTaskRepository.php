<?php

namespace WPML\Core\BackgroundTask\Repository;

use WPML\Core\BackgroundTask\Command\DeleteBackgroundTask;
use WPML\Core\BackgroundTask\Model\BackgroundTask;
use WPML\Core\BackgroundTask\Model\TaskEndpointInterface;
use WPML\FP\Fns;
use WPML\FP\Obj;

use function WPML\Container\make;

class BackgroundTaskRepository {
	protected $wpdb;

	private $deleteBackgroundTaskCommand;

	public function __construct( \wpdb $wpdb, DeleteBackgroundTask $deleteBackgroundTaskCommand ) {
		$this->wpdb = $wpdb;
		$this->deleteBackgroundTaskCommand = $deleteBackgroundTaskCommand;
	}

	public function getByTaskId( $task_id ) {
		$table = BackgroundTask::TABLE_NAME;
		$query = $this->wpdb->prepare( "SELECT * FROM {$this->wpdb->prefix}{$table} WHERE task_id = %s LIMIT 1", $task_id );
		$row   = $this->wpdb->get_row( $query, 'ARRAY_A' );
		$model = $this->createFromQueryResult( $row );

		return $model;
	}

	public function getLastIncompletedByType( $task_type, $payload = null ) {
		$table = BackgroundTask::TABLE_NAME;
		$and_payload = $payload ? $this->wpdb->prepare( "AND payload = %s", maybe_serialize( $payload ) ) : '';
		$query = $this->wpdb->prepare( "SELECT * FROM {$this->wpdb->prefix}{$table} WHERE task_type = %s {$and_payload} AND task_status != %s LIMIT 1", $task_type, BackgroundTask::TASK_STATUS_COMPLETED );
		$row   = $this->wpdb->get_row( $query, 'ARRAY_A' );
		if ( ! $row ) {
			return null;
		}
		$model = $this->createFromQueryResult( $row );

		return $model;
	}

	public function getAllByTaskStatus( array $statuses ) {
		$fields_in = wpml_prepare_in( $statuses, '%s' );

		$table = $this->wpdb->prefix . BackgroundTask::TABLE_NAME;
		$preparedQuery = $this->wpdb->prepare( "SELECT * FROM {$table} WHERE task_status IN ({$fields_in}) AND 1=%d", 1 );
		$allTasks = $this->wpdb->get_results( $preparedQuery, 'ARRAY_A' );

		$taskHandlers = [];
		$uniqueTasks = [];
		$duplicatedTasks = [];

		foreach ( $allTasks as $task ) {
			if (
				! array_key_exists( 'task_id', $task )
				|| ! array_key_exists( 'task_type', $task )
				|| ! array_key_exists( 'task_status', $task )
				|| ! array_key_exists( 'payload', $task )
			) {
				continue;
			}

			if ( ! array_key_exists( $task['task_type'], $taskHandlers ) ) {
				$taskHandler = make( $task['task_type'] );
				$taskHandlers[ $task['task_type'] ] = $taskHandler;
			} else {
				$taskHandler = $taskHandlers[ $task['task_type'] ];
			}

			if ( ! $taskHandler instanceof TaskEndpointInterface ) {
				$this->deleteBackgroundTaskCommand->run( $task['task_id'] );
				continue;
			}

			if ( ! $taskHandler->isValidTask( $task['task_id'] ) ) {
				continue;
			}

			$key = $task['task_status'] . md5( $task['task_type'] . serialize( $task['payload'] ) );
			if ( $task['task_status'] !== BackgroundTask::TASK_STATUS_COMPLETED && array_key_exists( $key, $uniqueTasks ) ) {
				$duplicatedTasks[] = $task['task_id'];
				continue;
			}

			$uniqueTasks[ $key ] = $this->createFromQueryResult( $task );
		}

		if ( ! empty( $duplicatedTasks ) ) {
			$table = $this->wpdb->prefix . BackgroundTask::TABLE_NAME;
			$fields_in = wpml_prepare_in( $duplicatedTasks, '%d' );
			$this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$table} WHERE task_id IN ({$fields_in})", $duplicatedTasks ) );
		}

		return array_values( $uniqueTasks );
	}

	public function getAllRunnableTasks() {
		return $this->getAllByTaskStatus( [ BackgroundTask::TASK_STATUS_INPROGRESS, BackgroundTask::TASK_STATUS_PENDING, BackgroundTask::TASK_STATUS_PAUSED ] );
	}


	private function getCountByTaskStatus( array $statuses ) {
		$fields_in = wpml_prepare_in( $statuses, '%s' );

		$table = $this->wpdb->prefix . BackgroundTask::TABLE_NAME;
		$preparedQuery = $this->wpdb->prepare( "SELECT COUNT(task_id) FROM {$table} WHERE task_status IN ({$fields_in}) AND 1=%d", 1 );
		return (int) $this->wpdb->get_var( $preparedQuery );
	}

	public function getCountRunnableTasks() {
		return $this->getCountByTaskStatus([ BackgroundTask::TASK_STATUS_INPROGRESS, BackgroundTask::TASK_STATUS_PENDING, BackgroundTask::TASK_STATUS_PAUSED ] );
	}


	public function createFromQueryResult( $data ) {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$get = Obj::propOr(null, Fns::__, $data);

		$task = new BackgroundTask();

		$task->setTaskId( $get( 'task_id' ) );
		$task->setTaskType( $get( 'task_type' ) );
		$task->setStatus( $get( 'task_status' ) );
		$task->setStartingDate( isset( $data['starting_date'] ) ? new \DateTime( $data['starting_date'] ) : null );
		$task->setTotalCount( $get( 'total_count' ) );
		$task->setCompletedCount( $get( 'completed_count' ) );
		$task->setCompletedIds( isset( $data['completed_ids'] ) ? unserialize( $data['completed_ids'] ) : null );
		$task->setPayload( isset( $data['payload'] ) ? unserialize( $data['payload'] ) : null );
		$task->setRetryCount( $get( 'retry_count' ) );

		return $task;
	}
}
