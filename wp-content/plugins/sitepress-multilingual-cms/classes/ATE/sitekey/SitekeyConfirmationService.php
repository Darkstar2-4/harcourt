<?php

namespace WPML\TM\ATE\Sitekey;

class SitekeyConfirmationService {

	private $sitekeyProvider;

	private $sitekeyApiClient;

	public function __construct(
		SitekeyProvider $sitekeyProvider,
		SitekeyApiClient $sitekeyApiClient
	) {
		$this->sitekeyProvider  = $sitekeyProvider;
		$this->sitekeyApiClient = $sitekeyApiClient;
	}

	public function confirm(): bool {
		if ( ! $this->sitekeyProvider->hasSitekey() ) {
			return false;
		}

		$success = $this->sitekeyApiClient->sendSitekey();

		if ( $success ) {
			SitekeyConfirmationFlag::markAsCompleted();
		}

		return $success;
	}
}
