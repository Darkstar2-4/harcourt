<?php

namespace WPML\TM\ATE\Loader;

use WPML\API\PostTypes;
use WPML\Setup\Option;
use WPML\TM\ATE\Loader\MarkPreviouslyUnsupportedContentAsCompletedInTEA\ExecutionStatus;
use WPML\TM\ATE\Loader\MarkPreviouslyUnsupportedContentAsCompletedInTEA\PostTypesMigration;
use WPML\TM\ATE\Loader\MarkPreviouslyUnsupportedContentAsCompletedInTEA\StringsAndPackagesMigration;
use WPML\TM\ATE\TranslateEverything\UntranslatedPackages;
use WPML\TM\ATE\TranslateEverything\UntranslatedPosts;
use WPML\WP\OptionManager;
use WPML\StringTranslation\Infrastructure\TranslateEverything\UntranslatedStringsFactory;
use function WPML\Container\make;

class MarkPreviouslyUnsupportedContentAsCompletedInTEA {

	private $postTypesMigration;

	private $executionStatus;

	public function __construct(
		PostTypesMigration $postTypesMigration,
		ExecutionStatus $executionStatus
	) {
		$this->postTypesMigration        = $postTypesMigration;
		$this->executionStatus           = $executionStatus;
	}


	public function run() {
		if ( $this->executionStatus->isFullyExecuted() ) {
			return;
		}

		if ( ! Option::shouldTranslateEverything() ) {
			$this->executionStatus->markPostTypesAsExecuted();
			if ( wpml_is_st_loaded() ) {
				$this->executionStatus->markPackagesAsExecuted();
			}

			return;
		}

		if ( ! $this->executionStatus->arePostTypesExecuted() ) {
			$this->postTypesMigration->run();
		}

		if ( ! $this->executionStatus->arePackagesExecuted() && wpml_is_st_loaded() ) {
			$stingAndPackagesMigration = make( StringsAndPackagesMigration::class );
			$stingAndPackagesMigration->run();
		}
	}

}
