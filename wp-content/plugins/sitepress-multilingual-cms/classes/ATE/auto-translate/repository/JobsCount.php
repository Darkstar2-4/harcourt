<?php

namespace WPML\TM\ATE\AutoTranslate\Repository;

use WPML\TM\ATE\Jobs;
use WPML\Translation\TranslateJobErrorServiceFactory;

class JobsCount implements JobsCountInterface {
	private $jobs;

	public function __construct( Jobs $jobs ) {
		$this->jobs = $jobs;
	}

	public function get(): array {
		return [
			'allCount'                          => $this->jobs->getCountOfInProgress(),
			'allAutomaticCount'                 => $this->jobs->getCountOfAutomaticInProgress( true ),
			'automaticWithoutLongstandingCount' => $this->jobs->getCountOfAutomaticInProgress( false ),
			'needsReviewCount'                  => $this->jobs->getCountOfNeedsReview(),
			'unsolvableJobsCount'               => TranslateJobErrorServiceFactory::create()->getCount(),
		];
	}

}
