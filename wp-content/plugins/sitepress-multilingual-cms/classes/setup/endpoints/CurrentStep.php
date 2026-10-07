<?php

namespace WPML\Setup\Endpoint;

use WPML\Ajax\IHandler;
use WPML\Collect\Support\Collection;
use WPML\Core\Component\PostHog\Application\Service\Event\EventInstanceService;
use WPML\Core\Component\PostHog\Domain\Event\Event;
use WPML\FP\Either;
use WPML\FP\Fns;
use WPML\FP\Logic;
use WPML\FP\Lst;
use WPML\FP\Obj;
use WPML\FP\Relation;
use WPML\Infrastructure\WordPress\Component\PostHog\Domain\Event\SetupWizard\Capture\CaptureWizardFirstStep;
use WPML\PostHog\Event\CaptureWizardFirstStepEvent;
use WPML\PostHog\Event\CaptureWizardStepEvent;
use WPML\PostHog\State\PostHogState;
use WPML\Setup\Option;

class CurrentStep implements IHandler {

	const STEP_TRANSLATION_SETTINGS = 'translationSettings';
	const STEP_HIGH_COSTS_WARNING = 'highCostsWarning';
	const STEPS = [
		'languages',
		'address',
		'license',
		'aiTranslation',
		'translation',
		self::STEP_TRANSLATION_SETTINGS,
		self::STEP_HIGH_COSTS_WARNING,
		'pauseTranslateEverything',
		'support',
		'plugins',
		'finished'
	];

	public function run( Collection $data ) {
		$isValid = Logic::allPass( [
			Lst::includes( Fns::__, self::STEPS ),
			Logic::ifElse(
				Relation::equals( 'languages' ),
				Fns::identity(),
				Fns::always( ! empty( Option::getTranslationLangs() ) )
			),
		] );

		return Either::fromNullable( Obj::prop( 'currentStep', $data ) )
		             ->filter( $isValid )
		             ->map( Fns::tap( function ( $nextStep ) {
			             $this->captureStepEvent( $nextStep );
		             } ) )
		             ->map( [ Option::class, 'saveCurrentStep' ] );
	}


	private function captureStepEvent( $nextStep ) {

		if ( ! PostHogState::isEnabled() ) {
			return;
		}

		$completedStep = Option::getCurrentStep();

		if ( $completedStep === $nextStep ) {
			return;
		}

		$eventData = [
			'completed_step' => $completedStep,
			'next_step'      => $nextStep,
		];

		switch ( $completedStep ) {
			case 'languages':
				$event = ( new EventInstanceService() )->getWizardFirstStepCompletedEvent( $eventData );
				CaptureWizardFirstStepEvent::capture( $event );
				break;
			default:
				$event = ( new EventInstanceService() )->getWizardStepCompletedEvent( $eventData );
				CaptureWizardStepEvent::capture( $event );
				break;
		}

	}

}
