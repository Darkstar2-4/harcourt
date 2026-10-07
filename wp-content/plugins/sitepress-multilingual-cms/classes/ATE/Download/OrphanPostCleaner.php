<?php

namespace WPML\TM\ATE\Download;

use WPML\TM\ATE\Download\OrphanPostCleaner\OrphanPostRepository;
use WPML\TM\ATE\Download\OrphanPostCleaner\ProcessCounter;
use WPML\TM\ATE\Download\OrphanPostCleaner\Sleeper;
use WPML\TM\Jobs\JobLog;

class OrphanPostCleaner {

	const WAIT_INTERVAL_SECONDS = 2;
	const MAX_WAIT_RETRIES = 10;

	private $repository;

	private $counter;

	private $sleeper;

	private $maxPostIdBefore;

	private $cleanupNeeded = false;

	public function __construct(
		OrphanPostRepository $repository,
		ProcessCounter $counter,
		Sleeper $sleeper
	) {
		$this->repository = $repository;
		$this->counter = $counter;
		$this->sleeper = $sleeper;
	}

	public function incrementProcessCounter() {
		$this->counter->increment();
	}

	public function decrementProcessCounter() {
		$this->counter->decrement();
	}

	public function recordStateBeforeInsert() {
		$this->maxPostIdBefore = $this->repository->getMaxPostId();
	}

	public function markCleanupNeeded() {
		$this->cleanupNeeded = true;
	}

	public function tryCleanup() {
		if ( ! $this->cleanupNeeded || $this->maxPostIdBefore === null ) {
			return;
		}

		$retries = 0;
		$counterValue = $this->counter->get();

		while ( $counterValue > 0 && $retries < self::MAX_WAIT_RETRIES ) {
			$this->sleeper->sleep( self::WAIT_INTERVAL_SECONDS );
			$retries++;
			$counterValue = $this->counter->get();
		}

		$this->doCleanup();
	}

	private function doCleanup() {
		$orphanIds = $this->repository->getOrphanPostIds( $this->maxPostIdBefore );

		JobLog::add( 'orphan_cleanup_started', [
			'max_post_id_before' => $this->maxPostIdBefore,
			'orphan_count'       => count( $orphanIds ),
			'orphan_post_ids'    => array_map( 'intval', $orphanIds ),
		] );

		foreach ( $orphanIds as $postId ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( "[OrphanPostCleaner] Deleting orphan post with ID: {$postId}" );
			}
			JobLog::add( 'orphan_post_deleted', [
				'post_id' => (int) $postId,
			] );
			$this->repository->deletePost( $postId );
		}

		$this->reset();
	}

	private function reset() {
		$this->maxPostIdBefore = null;
		$this->cleanupNeeded = false;
	}
}
