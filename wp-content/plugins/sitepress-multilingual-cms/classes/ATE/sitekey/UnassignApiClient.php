<?php

namespace WPML\TM\ATE\Sitekey;

class UnassignApiClient {

	private $amsApi;

	private $logger;

	public function __construct( \WPML_TM_AMS_API $amsApi, SitekeyLogger $logger ) {
		$this->amsApi = $amsApi;
		$this->logger = $logger;
	}

	public function unassign( $sitekey ): UnassignSitekeyResult {
		try {
			$rawResult = $this->amsApi->unassign_sitekey( $sitekey );
			$result    = UnassignSitekeyResult::fromApiResponse( $rawResult );
		} catch ( \Exception $e ) {
			$result = new UnassignSitekeyResult(
				UnassignSitekeyResult::STATUS_RETRYABLE_ERROR,
				$e->getMessage()
			);
		}

		if ( $result->isSuccess() ) {
			$this->logger->logSuccess( 'Site key unassigned from AMS successfully', $sitekey );
		} else {
			$this->logger->logErrorWithKey( 'Site key unassign failed: ' . $result->getError(), $sitekey );
		}

		return $result;
	}
}
