<?php

namespace WPML\Infrastructure\WordPress\Component\PostHog\Domain\Event\SetupWizard\Capture;

use WPML\Core\Component\PostHog\Application\Repository\PostHogStateRepositoryInterface;
use WPML\Core\Component\PostHog\Domain\Event\EventInterface;
use WPML\Core\Component\PostHog\Domain\Repository\SetupWizardLastStepSubmissionTimeRepositoryInterface;
use WPML\Core\Component\PostHog\Domain\Repository\SetupWizardStartTimeRepositoryInterface;
use WPML\Core\Component\PostHog\Domain\Repository\SetupWizardUUIDRepositoryInterface;
use WPML\Core\Component\PostHog\Domain\TrackingMode;
use WPML\Core\Port\Remote\RemoteInterface;
use WPML\Infrastructure\WordPress\Component\PostHog\Domain\Event\Capture;

class CaptureWizardFirstStep extends Capture {

  const FIRST_STEP_NAME = 'languages';

  private $wizardUUIDRepository;

  private $wizardStartTimeRepository;

  private $wizardLastStepSubmissionTimeRepository;


  public function __construct(
    PostHogStateRepositoryInterface $postHogStateRepository,
    SetupWizardUUIDRepositoryInterface $wizardUUIDRepository,
    SetupWizardStartTimeRepositoryInterface $wizardStartTimeRepository,
    SetupWizardLastStepSubmissionTimeRepositoryInterface $wizardLastStepSubmissionTimeRepository,
    RemoteInterface $remote
  ) {
    $this->wizardUUIDRepository               = $wizardUUIDRepository;
    $this->wizardStartTimeRepository          = $wizardStartTimeRepository;
    $this->wizardLastStepSubmissionTimeRepository = $wizardLastStepSubmissionTimeRepository;
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

    $wizardUUID = $this->wizardUUIDRepository->get();

    if ( ! TrackingMode::isEventAllowed( $this->postHogStateRepository->getTrackingMode(), false ) || ! $wizardUUID ) {
      return false;
    }

    $stepSubmissionTime  = time();
    $wizardStartTime     = $this->wizardStartTimeRepository->get( $wizardUUID );
    $stepDurationSeconds = $wizardStartTime ?
      $stepSubmissionTime - $wizardStartTime :
      null;

    $this->wizardLastStepSubmissionTimeRepository->save( $stepSubmissionTime );

    $event->addProperties(
      [
      'wizard_uuid'           => $wizardUUID,
      'step_duration_seconds' => $stepDurationSeconds,
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
