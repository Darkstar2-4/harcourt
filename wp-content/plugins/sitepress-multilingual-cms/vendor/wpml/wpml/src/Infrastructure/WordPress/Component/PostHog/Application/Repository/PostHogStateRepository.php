<?php

namespace WPML\Infrastructure\WordPress\Component\PostHog\Application\Repository;

use WPML\Core\Component\PostHog\Application\Repository\PostHogStateRepositoryInterface;
use WPML\Core\Component\PostHog\Domain\TrackingMode;
use WPML\Core\Port\Persistence\OptionsInterface;

class PostHogStateRepository implements PostHogStateRepositoryInterface {

  private $options;

  const LEGACY_OPTION_NAME     = 'wpml_posthog_enabled';
  const OPTION_NAME_TRACKING_MODE = 'wpml_posthog_tracking_mode';


  public function __construct( OptionsInterface $options ) {
    $this->options = $options;
  }


  public function isEnabled(): bool {
    return TrackingMode::toBool( $this->getTrackingMode() );
  }


  public function setIsEnabled( bool $isEnabled ) {
    $this->options->save( self::OPTION_NAME_TRACKING_MODE, TrackingMode::fromBool( $isEnabled ) );
  }


  public function getTrackingMode(): string {
    $storedMode = $this->options->get( self::OPTION_NAME_TRACKING_MODE );

    if ( is_string( $storedMode ) && TrackingMode::isValid( $storedMode ) ) {
      return $storedMode;
    }

    $legacyValue = $this->options->get( self::LEGACY_OPTION_NAME, null );

    if ( $legacyValue !== null ) {
      $migratedMode = (bool) $legacyValue ? TrackingMode::ALL : TrackingMode::DISABLED;
      $this->options->save( self::OPTION_NAME_TRACKING_MODE, $migratedMode );
      $this->options->delete( self::LEGACY_OPTION_NAME );
      return $migratedMode;
    }

    return TrackingMode::DISABLED;
  }


  public function setTrackingMode( string $mode ) {
    $safeMode = TrackingMode::isValid( $mode ) ? $mode : TrackingMode::DISABLED;
    $this->options->save( self::OPTION_NAME_TRACKING_MODE, $safeMode );
  }


}
