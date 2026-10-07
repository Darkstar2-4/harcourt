<?php

namespace WPML\PostHog\Event;

use WPML\Core\Component\PostHog\Application\Service\Config\ConfigService;
use WPML\Core\Component\PostHog\Application\Service\Event\CaptureEventService;
use WPML\Core\Component\PostHog\Domain\Event\EventInterface;
use WPML\Infrastructure\WordPress\Component\PostHog\Domain\Event\SetupWizard\Capture\CaptureWizardStarted;
use WPML\PHP\Exception\RemoteException;

class CaptureWizardStartedEvent {

	public static function capture( EventInterface $event, $personProps = [] ) {
		$postHogConfig = ( new ConfigService() )->create();

		global $wpml_dic;

		$postHogCaptureEvent = $wpml_dic->make( CaptureWizardStarted::class );

		$postHogCaptureEventService = $wpml_dic->make( CaptureEventService::class, [
			':captureEvent' => $postHogCaptureEvent,
		] );

		try {
			return $postHogCaptureEventService->capture(
				$postHogConfig,
				$event,
				$personProps
			);
		} catch ( RemoteException $e ) {
			return false;
		}
	}

}
