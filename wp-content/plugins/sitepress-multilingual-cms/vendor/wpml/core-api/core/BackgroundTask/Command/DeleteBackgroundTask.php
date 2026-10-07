<?php

namespace WPML\Core\BackgroundTask\Command;

use WPML\Core\BackgroundTask\Model\BackgroundTask;

class DeleteBackgroundTask {
	private $wpdb;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}


	public function run( $taskId ) {
		$this->wpdb->delete(
			$this->wpdb->prefix . BackgroundTask::TABLE_NAME,
			[ 'task_id' => $taskId ]
		);
	}
}
