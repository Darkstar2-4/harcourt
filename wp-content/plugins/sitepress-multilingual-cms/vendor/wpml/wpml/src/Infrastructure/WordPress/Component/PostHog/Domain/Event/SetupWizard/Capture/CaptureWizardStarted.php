<?php

namespace WPML\Infrastructure\WordPress\Component\PostHog\Domain\Event\SetupWizard\Capture;

use WPML\Core\Component\PostHog\Application\Repository\PostHogStateRepositoryInterface;
use WPML\Core\Component\PostHog\Domain\Event\EventInterface;
use WPML\Core\Component\PostHog\Domain\Event\SetupWizard\SetupWizardUUIDInterface;
use WPML\Core\Component\PostHog\Domain\Repository\SetupWizardStartTimeRepositoryInterface;
use WPML\Core\Component\PostHog\Domain\TrackingMode;
use WPML\Core\Port\Remote\RemoteInterface;
use WPML\Infrastructure\WordPress\Component\PostHog\Domain\Event\Capture;

class CaptureWizardStarted extends Capture {

  private $wizardUUID;

  private $wizardStartTimeRepository;


  public function __construct(
    PostHogStateRepositoryInterface $postHogStateRepository,
    SetupWizardUUIDInterface $wizardUUID,
    SetupWizardStartTimeRepositoryInterface $wizardStartTimeRepository,
    RemoteInterface $remote
  ) {
    $this->wizardUUID                = $wizardUUID;
    $this->wizardStartTimeRepository = $wizardStartTimeRepository;
    parent::__construct( $postHogStateRepository, $remote );
  }


  public function capture(
    string $apiKey,
    string $host,
    string $distinctId,
    string $sessionId,
    EventInterface $event,
    array $personProperties = []
  ): bool {

    if ( ! TrackingMode::isEventAllowed( $this->postHogStateRepository->getTrackingMode(), false ) ) {
      return false;
    }

    $wizardUUID = $this->wizardUUID->create();
    $wizardStartTime = time();
    $this->wizardStartTimeRepository->save( $wizardUUID, $wizardStartTime );

    $event->addProperties(
      [
      'wizard_uuid'       => $wizardUUID,
      'wizard_start_time' => $wizardStartTime,
       ]
    );

    return parent::capture(
      $apiKey,
      $host,
      $distinctId,
      $sessionId,
      $event,
      $personProperties
    );
  }


}
