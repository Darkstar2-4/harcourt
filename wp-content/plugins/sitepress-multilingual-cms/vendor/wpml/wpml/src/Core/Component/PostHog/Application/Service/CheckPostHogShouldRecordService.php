<?php

namespace WPML\Core\Component\PostHog\Application\Service;

use WPML\Core\Component\PostHog\Application\Repository\PostHogCacheStateRepositoryInterface;
use WPML\Core\Component\PostHog\Application\Repository\PostHogStateRepositoryInterface;
use WPML\Core\Port\PluginInterface;
use WPML\Core\SharedKernel\Component\Installer\Application\Query\WpmlSiteKeyQueryInterface;
use WPML\Core\SharedKernel\Component\Setting\Application\Query\TranslationEditorQueryInterface;
use WPML\Core\SharedKernel\Component\WpmlOrgClient\Application\Service\PostHogRecording\PostHogRecordingService;

class CheckPostHogShouldRecordService {

  const DEFAULT_RECHECK_TTL_SECONDS = 86400;

  private $siteKeyQuery;

  private $postHogRecordingService;

  private $postHogStateRepository;

  private $cacheStateRepository;

  private $retryService;

  private $plugin;

  private $translationEditorQuery;


  public function __construct(
    WpmlSiteKeyQueryInterface $siteKeyQuery,
    PostHogRecordingService $postHogRecordingService,
    PostHogStateRepositoryInterface $postHogStateRepository,
    PostHogCacheStateRepositoryInterface $cacheStateRepository,
    RetryService $retryService,
    PluginInterface $plugin,
    TranslationEditorQueryInterface $translationEditorQuery
  ) {
    $this->siteKeyQuery            = $siteKeyQuery;
    $this->postHogRecordingService = $postHogRecordingService;
    $this->postHogStateRepository  = $postHogStateRepository;
    $this->cacheStateRepository    = $cacheStateRepository;
    $this->retryService            = $retryService;
    $this->plugin                  = $plugin;
    $this->translationEditorQuery  = $translationEditorQuery;
  }


  public function run( bool $forceRefresh = false ): bool {
    $siteKey = $this->siteKeyQuery->get();

    if ( ! $siteKey ) {
      return false;
    }

    if ( $this->retryService->isInRetryMode() ) {
      if ( ! $this->retryService->shouldRetry() ) {
        return false;
      }
    } elseif ( ! $forceRefresh && ! $this->cacheStateRepository->isStale( $this->getRecheckTtlSeconds() ) ) {
      return true;
    }

    if ( ! $this->cacheStateRepository->acquireProcessingLock() ) {
      return true;
    }

    try {
      $previousMode = $this->postHogStateRepository->getTrackingMode();
      $wpmlVersion  = $this->plugin->getVersion();
      $teaState     = $this->getTeaState();

      $result = $this->postHogRecordingService->run( $siteKey, 'default', $wpmlVersion, $teaState );

      if ( $result['isResponseError'] ) {
        $this->handleResponseError();
        return false;
      }

      $this->cacheStateRepository->setLastChecked( time() );

      if ( $result['trackingMode'] !== $previousMode ) {
        $this->postHogStateRepository->setTrackingMode( $result['trackingMode'] );
      }

      $this->retryService->reset();
      return true;
    } finally {
      $this->cacheStateRepository->releaseProcessingLock();
    }
  }


  public function handleResponseError() {
    $this->retryService->incrementAttempt();

    if ( $this->retryService->hasExceededMaxAttempts() ) {
      $this->retryService->reset();
      $this->cacheStateRepository->setLastChecked( time() );
    }
  }


  private function getTeaState(): string {
    $setting = $this->translationEditorQuery->getTranslationEditorSetting();

    if ( $setting === null ) {
      return '';
    }

    return $setting->getValue();
  }


  private function getRecheckTtlSeconds(): int {
    try {
      return defined( 'WPML_POSTHOG_RECHECK_TTL_SECONDS' )
        ? (int) constant( 'WPML_POSTHOG_RECHECK_TTL_SECONDS' )
        : self::DEFAULT_RECHECK_TTL_SECONDS;
    } catch ( \Throwable $e ) {
      return self::DEFAULT_RECHECK_TTL_SECONDS;
    }
  }


}
