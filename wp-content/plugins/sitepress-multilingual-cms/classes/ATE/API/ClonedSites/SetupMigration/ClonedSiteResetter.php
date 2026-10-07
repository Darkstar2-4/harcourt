<?php

namespace WPML\TM\ATE\ClonedSites\SetupMigration;

use WPML\TM\ATE\ClonedSites\AutoMigration\Handler as AutoMigrationHandler;
use WPML\TM\ATE\ClonedSites\SetupMigration\Resetter\AmsCredentialsCleaner;
use WPML\TM\ATE\ClonedSites\SetupMigration\Resetter\SiteKeyCleaner;
use WPML\TM\ATE\ClonedSites\SetupMigration\Resetter\SetupStepRewinder;

class ClonedSiteResetter {

	private $credentialsCleaner;

	private $siteKeyCleaner;

	private $setupStepRewinder;

	public function __construct(
		AmsCredentialsCleaner $credentialsCleaner,
		SiteKeyCleaner $siteKeyCleaner,
		SetupStepRewinder $setupStepRewinder
	) {
		$this->credentialsCleaner = $credentialsCleaner;
		$this->siteKeyCleaner     = $siteKeyCleaner;
		$this->setupStepRewinder  = $setupStepRewinder;
	}

	public function reset( string $currentStep ): string {
		$this->credentialsCleaner->clear();
		$this->siteKeyCleaner->unregister();
		AutoMigrationHandler::clearMigrationFlag();

		return $this->setupStepRewinder->maybeRewindCurrentStep( $currentStep );
	}
}
