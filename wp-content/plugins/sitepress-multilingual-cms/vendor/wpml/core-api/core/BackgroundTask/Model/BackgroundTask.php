<?php

namespace WPML\Core\BackgroundTask\Model;

use WPML\FP\Obj;

class BackgroundTask {
	const TABLE_NAME = 'icl_background_task';

	const ITEMS_COUNT_IN_TASK = 10;

	const TASK_TYPE_DEFAULT = 'Default';
	const TASK_TYPE_PROCESS_NEW_TRANSLATABLE_FIELDS = 'ProcessNewTranslatableFields';

	const TASK_STATUS_PENDING = 0;
	const TASK_STATUS_INPROGRESS = 1;
	const TASK_STATUS_PAUSED = 2;
	const TASK_STATUS_COMPLETED = 3;

	const EXPIRE_AFTER_SECONDS = 3600;
	const MAX_RETRY_COUNT = 2;

	private $taskId;

	private $taskType = self::TASK_TYPE_DEFAULT;

	private $taskStatus = self::TASK_STATUS_PENDING;

	private $startingDate;

	private $totalCount = 0;

	private $completedCount = 0;

	private $completedIds = [];

	private $payload = [];

	private $retryCount = 0;

	private static $itemsCountInTask = self::ITEMS_COUNT_IN_TASK;

	public function serialize() {
		return [
			'task_type'       => $this->getTaskType(),
			'task_status'     => $this->getStatus(),
			'starting_date'   => ( $this->hasStartingDate() ) ? $this->getStartingDate()->format('Y-m-d H:i:s') : null,
			'total_count'     => $this->getTotalCount(),
			'completed_count' => $this->getCompletedCount(),
			'completed_ids'   => ( $this->getCompletedIds() ) ? serialize( $this->getCompletedIds() ) : null,
			'payload'         => serialize( $this->getPayload() ),
			'retry_count'     => $this->getRetryCount(),
		];
	}

	public function finish() {
		$this->taskStatus     = static::TASK_STATUS_COMPLETED;
		$this->completedCount = $this->totalCount;
	}

	public function setTaskId( $taskId ) {
		$this->taskId = ( is_numeric( $taskId ) ) ? (int) $taskId : null;
	}

	public function getTaskId() {
		return $this->taskId;
	}

	public function setTaskType( $taskType ) {
		$this->taskType = ( is_string( $taskType ) ) ? $taskType : self::TASK_TYPE_DEFAULT;
	}

	public function getTaskType() {
		return $this->taskType;
	}


	public function setStatus( $status ) {
		$this->taskStatus = ( is_numeric( $status ) ) ? (int) $status : self::TASK_STATUS_PENDING;
	}

	public function getStatus() {
		return (int) $this->taskStatus;
	}

	public function isStatusPending() {
		return ( self::TASK_STATUS_PENDING === $this->getStatus() );
	}

	public function isStatusInProgress() {
		return ( self::TASK_STATUS_INPROGRESS === $this->getStatus() );
	}

	public function isStatusPaused() {
		return ( self::TASK_STATUS_PAUSED === $this->getStatus() );
	}

	public function isStatusCompleted() {
		return ( self::TASK_STATUS_COMPLETED === $this->getStatus() );
	}

	public function getStatusName() {
		if ( $this->isStatusPending() ) {
			return self::TASK_STATUS_PENDING;
		}
		if ( $this->isStatusInProgress() ) {
			return self::TASK_STATUS_INPROGRESS;
		}
		if ( $this->isStatusPaused() ) {
			return self::TASK_STATUS_PAUSED;
		}
		if ( $this->isStatusCompleted() ) {
			return self::TASK_STATUS_COMPLETED;
		}
	}

	public function setStartingDate( $startingDate ) {
		$this->startingDate = $startingDate;
	}

	public function getStartingDate() {
		return $this->startingDate;
	}

	public function hasStartingDate() {
		return ( $this->getStartingDate() instanceof \DateTime );
	}

	public function setTotalCount( $totalCount ) {
		$this->totalCount = ( is_numeric( $totalCount ) ) ? (int) $totalCount : 0;
	}

	public function getTotalCount() {
		return ( is_numeric( $this->totalCount ) ) ? (int) $this->totalCount : $this->totalCount;
	}

	public function addCompletedCount( $completed_count ) {
		$this->completedCount += $completed_count;
	}

	public function getCompletedCount() {
		return ( is_numeric( $this->completedCount ) ) ? (int) $this->completedCount : $this->completedCount;
	}

	public function setCompletedCount( $completedCount ) {
		$this->completedCount = ( is_numeric( $completedCount ) ) ? $completedCount : 0;
	}

	public function addCompletedIds( $completed_ids ) {
		$this->completedIds = array_merge(
			$this->getCompletedIds(),
			$completed_ids
		);
	}

	public function getCompletedIds() {
		return $this->completedIds;
	}

	public function setCompletedIds( $completedIds ) {
		$this->completedIds = $completedIds;
	}

	public function hasCompletedIds() {
		return ( ! is_null( $this->completedIds ) );
	}

	public function setPayload( $payload ) {
		$this->payload = ( is_array( $payload ) ) ? $payload : [];
	}

	public function getPayload() {
		return $this->payload;
	}


	public function setRetryCount( $retryCount ) {
		$this->retryCount = ( is_numeric( $retryCount ) ) ? (int) $retryCount : 0;
	}

	public function getRetryCount() {
		return (int) $this->retryCount;
	}
}
