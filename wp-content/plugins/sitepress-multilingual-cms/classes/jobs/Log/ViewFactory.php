<?php

namespace WPML\TM\Jobs\Log;

use WPML\Collect\Support\Collection;
use WPML\TM\Jobs\JobLog;

class ViewFactory {

	public function create() {
		$summaries        = JobLog::getSummaries();
		$isLoggingEnabled = JobLog::isEnabled();

		return new View( new Collection( $summaries ), $isLoggingEnabled );
	}
}
