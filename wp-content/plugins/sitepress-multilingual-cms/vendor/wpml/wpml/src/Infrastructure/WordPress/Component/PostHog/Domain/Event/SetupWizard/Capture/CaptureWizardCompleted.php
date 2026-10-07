<?php

namespace WPML\Infrastructure\WordPress\Component\PostHog\Domain\Event\SetupWizard\Capture;

use WPML\Core\Component\PostHog\Application\Repository\PostHogStateRepositoryInterface;
use WPML\Core\Component\PostHog\Domain\Event\EventInterface;
use WPML\Core\Component\PostHog\Domain\Repository\SetupWizardStartTimeRepositoryInterface;
use WPML\Core\Component\PostHog\Domain\Repository\SetupWizardUUIDRepositoryInterface;
use WPML\Core\Component\PostHog\Domain\TrackingMode;
use WPML\Core\Port\Remote\RemoteInterface;
use WPML\Infrastructure\WordPress\Component\PostHog\Domain\Event\Capture;

class CaptureWizardCompleted extends Capture {

  private $wizardUUIDRepository;

  private $wizardStartTimeRepository;


  public function __construct(
    PostHogStateRepositoryInterface $postHogStateRepository,
    SetupWizardUUIDRepositoryInterface $wizardUUIDRepository,
    SetupWizardStartTimeRepositoryInterface $wizardStartTimeRepository,
    RemoteInterface $remote
  ) {
    $this->wizardUUIDRepository      = $wizardUUIDRepository;
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

    $wizardUUID = $this->wizardUUIDRepository->get();
    $now = time();
    $wizardStartTime = $wizardUUID ?
      $this->wizardStartTimeRepository->get( $wizardUUID ) :
      false;
    $wizardDurationSeconds = $wizardStartTime ? $now - $wizardStartTime : 0;

    $event->addProperties(
      [
      'wizard_uuid'             => $wizardUUID,
      'wizard_duration_seconds' => $wizardDurationSeconds,
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
