<?php

namespace WPML\UserInterface\Web\Core\Component\Preferences\Application\Endpoint\SaveAutomaticTranslationsSettings;

use WPML\Core\Component\ATE\Application\Service\EngineServiceException;
use WPML\Core\Component\ATE\Application\Service\EnginesServiceInterface;
use WPML\Core\Component\PostHog\Application\Service\Config\ConfigService;
use WPML\Core\Component\PostHog\Application\Service\Event\CaptureEventService;
use WPML\Core\Component\PostHog\Application\Service\Event\EventInstanceService;
use WPML\Core\Component\Translation\Application\Repository\SettingsRepository;
use WPML\Core\Component\Translation\Application\Service\SettingsService;
use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\PHP\Exception\Exception;

class SaveAutomaticTranslationsSettingsController implements EndpointInterface {

  private $settingsRepository;

  private $settingsService;

  private $engineService;

  private $enginesBuilder;

  private $configService;

  private $captureEventService;

  private $eventInstanceService;


  public function __construct(
    SettingsRepository $settingsRepository,
    SettingsService $settingsService,
    EnginesServiceInterface $engineService,
    EnginesBuilder $enginesBuilder,
    ConfigService $configService,
    CaptureEventService $captureEventService,
    EventInstanceService $eventInstanceService
  ) {
    $this->settingsRepository   = $settingsRepository;
    $this->settingsService      = $settingsService;
    $this->engineService        = $engineService;
    $this->enginesBuilder       = $enginesBuilder;
    $this->configService        = $configService;
    $this->captureEventService  = $captureEventService;
    $this->eventInstanceService = $eventInstanceService;
  }


  public function handle( $requestData = null ): array {
    $this->engineService->flushCache();

    try {
      if (
        isset( $requestData['engines'] ) &&
        is_array( $requestData['engines'] ) &&
        ! empty( $requestData['engines'] )
      ) {
        $engines = $this->enginesBuilder->build( $requestData['engines'] );
        $this->engineService->update( $engines );
      }
    } catch ( EngineServiceException $e ) {
      return [
        'status'  => false,
        'message' => $e->getMessage(),
      ];
    }


    if ( isset( $requestData['reviewMode'] ) && is_string( $requestData['reviewMode'] ) ) {
      $this->settingsService->saveReviewOption( $requestData['reviewMode'] );
    }

    if ( isset( $requestData['shouldTranslateAutomaticallyDrafts'] ) ) {
      $this->settingsRepository->saveShouldTranslateAutomaticallyDrafts(
        (bool) $requestData['shouldTranslateAutomaticallyDrafts']
      );
    }

    $this->captureAutomaticTranslationSettingsSaved( $requestData );

    return [
      'status' => true,
    ];
  }


  private function captureAutomaticTranslationSettingsSaved( $requestData ) {
    if ( ! $requestData ) {
      return;
    }

    try {

      $config = $this->configService->create();

      $isPtcEngineSelected = isset( $requestData['isPtcEngineSelected'] ) && $requestData['isPtcEngineSelected'];

      $eventProperties = $this->buildEventProperties( $requestData, $isPtcEngineSelected );

      $eventInstance = $this
          ->eventInstanceService
          ->getAutomaticTranslationSettingsSavedEvent( $eventProperties );

      $this->captureEventService->capture(
        $config,
        $eventInstance
      );
    } catch ( Exception $e ) {
    }
  }


  private function buildEventProperties( $requestData, $isPtcEngineSelected ) {
    $currentSettings = $this->settingsRepository->getSettings();

    $reviewMode = null;
    if ( isset( $requestData['reviewMode'] ) ) {
      $reviewMode = $requestData['reviewMode'];
    } else {
      $currentReviewMode = $currentSettings->getReviewMode();
      $reviewMode        = $currentReviewMode ? $currentReviewMode->getValue() : null;
    }

    $translateDrafts = null;
    if ( isset( $requestData['shouldTranslateAutomaticallyDrafts'] ) ) {
      $translateDrafts = (bool) $requestData['shouldTranslateAutomaticallyDrafts'];
    } else {
      $translateDrafts = $this->settingsRepository->shouldTranslateAutomaticallyDrafts();
    }

    $autoTranslateWhenEditorOpens = isset( $requestData['autoTranslateWhenEditorOpens'] )
      ? (bool) $requestData['autoTranslateWhenEditorOpens']
      : null;

    $eventProperties = [
      'translation_engine'               => $isPtcEngineSelected ? 'ptc' : 'other',
      'review_mode'                      => $reviewMode,
      'translate_drafts'                 => $translateDrafts,
      'auto_translate_when_editor_opens' => $autoTranslateWhenEditorOpens,
    ];

    if (
      $isPtcEngineSelected &&
      isset( $requestData['websiteContext'] ) &&
      is_array( $requestData['websiteContext'] )
    ) {
      $websiteContext             = $requestData['websiteContext'];
      $eventProperties['context'] = [
        'site_topic'    => $websiteContext['site_topic'] ?? '',
        'site_purpose'  => $websiteContext['site_purpose'] ?? '',
        'site_audience' => $websiteContext['site_audience'] ?? '',
      ];
    }

    return $eventProperties;
  }


}
