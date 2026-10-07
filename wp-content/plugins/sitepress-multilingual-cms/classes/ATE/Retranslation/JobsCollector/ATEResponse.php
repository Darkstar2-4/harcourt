<?php

namespace WPML\TM\ATE\Retranslation\JobsCollector;

class ATEResponse {

	private $jobIds;

	private $currentPage;

	private $totalPages;

	private $retranslationFinished;

	public function __construct( bool $retranslationFinished, array $jobIds, int $currentPage, int $totalPages ) {
		$this->retranslationFinished = $retranslationFinished;
		$this->jobIds                = $jobIds;
		$this->currentPage           = $currentPage;
		$this->totalPages            = $totalPages;
	}

	public function getJobIds() {
		return $this->jobIds;
	}

	public function getCurrentPage() {
		return $this->currentPage;
	}

	public function getTotalPages() {
		return $this->totalPages;
	}

	public function isRetranslationFinished() {
		return $this->retranslationFinished;
	}
}
