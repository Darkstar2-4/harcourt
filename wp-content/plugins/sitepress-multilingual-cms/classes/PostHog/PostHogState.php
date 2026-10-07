<?php

namespace WPML\PostHog\State;

use WPML\Core\Component\PostHog\Domain\TrackingMode;
use WPML\Infrastructure\WordPress\Component\PostHog\Application\Repository\PostHogStateRepository;
use WPML\Infrastructure\WordPress\Port\Persistence\Options;

class PostHogState {

	public static function isEnabled() {
		return TrackingMode::toBool( static::getTrackingMode() );
	}

	public static function getTrackingMode(): string {
		$options    = new Options();
		$storedMode = $options->get( 'wpml_posthog_tracking_mode' );

		if ( TrackingMode::isValid( $storedMode ) ) {
			return $storedMode;
		}

		return ( new PostHogStateRepository( new Options() ) )->isEnabled() ? TrackingMode::ALL : TrackingMode::DISABLED;
	}
}
