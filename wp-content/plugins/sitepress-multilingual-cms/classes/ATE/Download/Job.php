<?php

namespace WPML\TM\ATE\Download;

class Job {

	public $ateJobId;

	public $url;

	public $ateStatus;

	public $jobId;

	public $status = ICL_TM_IN_PROGRESS;

	public $isUnsolvable = false;

	public $message = '';

	public $errorType = null;

	public $errorData = null;

	public $originalElementId = null;

	public $elementId = null;

	public static function fromAteResponse( \stdClass $item ) {
		$job               = new self();
		$job->ateJobId     = $item->ate_id;
		$job->url          = $item->download_link;
		$job->ateStatus    = (int) $item->status;
		$job->isUnsolvable = (bool) ( $item->is_unsolvable ?? false );
		$job->message      = $item->message ?? '';
		$job->jobId        = (int) $item->id;
		if ( $job->isUnsolvable ) {
			$job->errorType = 'SyncError';
		}
		return $job;
	}

	public static function fromDb( \stdClass $row ) {
		$job           = new self();
		$job->ateJobId = $row->editor_job_id;
		$job->url      = $row->download_url;

		return $job;
	}
}
