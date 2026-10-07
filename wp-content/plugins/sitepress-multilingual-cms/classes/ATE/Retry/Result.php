<?php

namespace WPML\TM\ATE\Retry;

use WPML\Collect\Support\Collection;

class Result {
	public $jobsToProcess;

	public $processed = [];

	public function __construct() {
		$this->jobsToProcess = wpml_collect();
	}
}
