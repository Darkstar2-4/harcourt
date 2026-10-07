<?php

namespace WPML\TM\ATE\Loader\MarkPreviouslyUnsupportedContentAsCompletedInTEA;

use WPML\Infrastructure\WordPress\Component\StringPackage\Application\Query\PackageDefinitionQuery;
use WPML\StringTranslation\Infrastructure\TranslateEverything\UntranslatedStringsFactory;
use WPML\TM\ATE\TranslateEverything\UntranslatedPackages;

class StringsAndPackagesMigration {

	private $untranslatedPackages;

	private $untranslatedStringsFactory;

	private $translatablePackages;

	private $executionStatus;


	public function __construct(
		UntranslatedPackages $untranslatedPackages,
		UntranslatedStringsFactory $untranslated,
		PackageDefinitionQuery $translatablePackages,
		ExecutionStatus $executionStatus
	) {
		$this->untranslatedPackages       = $untranslatedPackages;
		$this->untranslatedStringsFactory = $untranslated;
		$this->translatablePackages       = $translatablePackages;
		$this->executionStatus            = $executionStatus;
	}

	public function run() {
		$translatablePackages = $this->translatablePackages->getNamesList();

		foreach ( $translatablePackages as $package ) {
			$this->untranslatedPackages->markTypeAsCompleted( $package );
		}

		$this->untranslatedStringsFactory->create()->markEverythingAsCompleted();

		$this->executionStatus->markPackagesAsExecuted();
	}

}
