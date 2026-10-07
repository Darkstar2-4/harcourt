<?php

namespace WPML\TM\ATE\ClonedSites\SetupMigration\Resetter;

use WPML\Setup\Option;

class SetupStepRewinder {

	public function maybeRewindCurrentStep( string $currentStep ): string {
		$stepsBeforeLicense = [ 'languages', 'address' ];
		if ( in_array( $currentStep, $stepsBeforeLicense, true ) ) {
			return $currentStep;
		}

		$step = 'license';
		Option::saveCurrentStep( $step );

		return $step;
	}
}
