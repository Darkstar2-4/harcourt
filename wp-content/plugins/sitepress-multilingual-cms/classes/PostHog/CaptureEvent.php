<?php

namespace WPML\PostHog\Event;

use WPML\Core\Component\PostHog\Application\Service\Config\ConfigService;
use WPML\Core\Component\PostHog\Application\Service\Event\CaptureEventService;
use WPML\Core\Component\PostHog\Domain\Event\EventInterface;
use WPML\PHP\Exception\RemoteException;

class CaptureEvent {

	public static function capture( EventInterface $event, $personProps = [] ) {
		$postHogConfig = ( new ConfigService() )->create();

		global $wpml_dic;

		$postHogCaptureEventService = $wpml_dic->make( CaptureEventService::class );

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
