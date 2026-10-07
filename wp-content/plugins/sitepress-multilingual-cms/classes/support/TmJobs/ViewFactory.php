<?php

namespace WPML\Support\TmJobs;

use function WPML\Container\make;
use WPML\TM\Jobs\JobLog;

class ViewFactory {

	public function create() {
		return new View( JobLog::getLogsCount() );
	}
}
