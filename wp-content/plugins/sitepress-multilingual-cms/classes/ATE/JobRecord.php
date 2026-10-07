<?php

namespace WPML\TM\ATE;

use stdClass;

class JobRecord {

	public $wpmlJobId;

	public $ateJobId;

	public $editTimestamp = 0;

	public function __construct( ?stdClass $dbRow = null ) {
		if ( $dbRow ) {
			$this->wpmlJobId = (int) $dbRow->job_id;
			$this->ateJobId  = (int) $dbRow->editor_job_id;
		}
	}

	public function isEditing() {
		$elapsedTime = time() - $this->editTimestamp;
		return $elapsedTime < DAY_IN_SECONDS;
	}
}
