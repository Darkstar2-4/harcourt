<?php

namespace WPML\TM\ATE\Retranslation;

class SinglePageBatchHandler {

	const NOT_FINISHED_IN_ATE = 'retranslations-no-finished-in-ate';
	const FINISHED_IN_WPML = 'retranslations-finished-in-wpml';
	const GO_TO_NEXT_PAGE = 'retranslate-next-page';


	private $jobsCollector;

	private $retranslationPreparer;

	public function __construct( JobsCollector $jobsCollector, RetranslationPreparer $retranslationPreparer ) {
		$this->jobsCollector         = $jobsCollector;
		$this->retranslationPreparer = $retranslationPreparer;
	}

	public function handle( int $pageNumber = 1 ): array {
		$jobsBatch = $this->jobsCollector->get( $pageNumber );

		if ( ! $jobsBatch->isRetranslationFinished() ) {
			return [ 'state' => self::NOT_FINISHED_IN_ATE, 'nextPage' => 0 ];
		}

		if ( $jobsBatch->getJobIds() ) {
			$this->retranslationPreparer->delegate( $jobsBatch->getJobIds() );
		}

		return $pageNumber >= $jobsBatch->getTotalPages()
			? [ 'state' => self::FINISHED_IN_WPML, 'nextPage' => 0 ]
			: [ 'state' => self::GO_TO_NEXT_PAGE, 'nextPage' => $pageNumber + 1 ];
	}

}
