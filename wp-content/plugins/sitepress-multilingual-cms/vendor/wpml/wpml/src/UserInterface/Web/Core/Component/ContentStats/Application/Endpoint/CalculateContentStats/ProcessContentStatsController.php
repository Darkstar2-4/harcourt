<?php

namespace WPML\UserInterface\Web\Core\Component\ContentStats\Application\Endpoint\CalculateContentStats;

use WPML\Core\Component\ReportContentStats\Application\Service\ContentChangeDetectionService;
use WPML\Core\Component\ReportContentStats\Application\Service\ContentStatsService;
use WPML\Core\Component\ReportContentStats\Application\Service\ContentStatsServiceException;
use WPML\Core\Component\ReportContentStats\Application\Service\EventReasonService;
use WPML\Core\Component\ReportContentStats\Application\Service\LastSentService;
use WPML\Core\Component\ReportContentStats\Application\Service\ProcessingLockService;
use WPML\Core\Component\ReportContentStats\Application\Service\ReportPreparer\ReportPreparerService;
use WPML\Core\Component\ReportContentStats\Application\Service\ReportSender\ReportSenderService;
use WPML\Core\Component\ReportContentStats\Application\Service\RetryService;
use WPML\Core\Port\Endpoint\EndpointInterface;

class ProcessContentStatsController implements EndpointInterface {

  private $lastSentService;

  private $contentStatsService;

  private $reportPreparerService;

  private $reportSenderService;

  private $retryService;

  private $processingLockService;

  private $eventReasonService;

  private $contentChangeDetectionService;


  public function __construct(
    ContentStatsService $contentStatsService,
    LastSentService $lastSentService,
    ReportPreparerService $reportPreparerService,
    ReportSenderService $reportSenderService,
    RetryService $retryService,
    ProcessingLockService $processingLockService,
    EventReasonService $eventReasonService,
    ContentChangeDetectionService $contentChangeDetectionService
  ) {
    $this->contentStatsService            = $contentStatsService;
    $this->lastSentService                = $lastSentService;
    $this->reportPreparerService          = $reportPreparerService;
    $this->reportSenderService            = $reportSenderService;
    $this->retryService                   = $retryService;
    $this->processingLockService          = $processingLockService;
    $this->eventReasonService             = $eventReasonService;
    $this->contentChangeDetectionService  = $contentChangeDetectionService;
  }


  public function handle( $requestData = null ): array {
    $ownerId = $requestData['ownerId'] ?? null;

    $lockError = $this->acquireOrRefreshLock( $ownerId );
    if ( $lockError ) {
      return $lockError;
    }

    try {
      $processedPostTypes = $this->contentStatsService->processPostTypes();


      if ( $processedPostTypes ) {
        return [
          'success' => true,
          'ownerId' => $ownerId,
          'message' => 'Calculation done for post types: ' .
                       implode( ',', $processedPostTypes ),
        ];
      }

      $sendError = $this->sendReportOrRetry( (string) $ownerId );
      if ( $sendError ) {
        return $sendError;
      }

      $this->finalizeReportingCycle();

      return [
        'success' => true,
        'message' => 'Report sent successfully!',
      ];
    } catch ( ContentStatsServiceException $e ) {
      return [
        'success' => false,
        'message' => $e->getMessage(),
      ];
    }
  }


  private function sendReportOrRetry( string $ownerId ) {
    $preparedReport         = $this->reportPreparerService->prepare();
    $reportSentSuccessfully = $this->reportSenderService->send( $preparedReport );

    if ( $reportSentSuccessfully ) {
      return null;
    }

    $this->retryService->incrementAttempt();

    if ( $this->retryService->hasExceededMaxAttempts() ) {
      $this->finalizeReportingCycle();

      return [
        'success' => false,
        'message' => 'Error when sending report - max retry attempts exceeded, will try again in 30 days',
      ];
    }

    return [
      'success' => false,
      'ownerId' => $ownerId,
      'message' => 'Error when sending report - will retry in ' .
                   $this->retryService->getRetryIntervalMinutes() . ' minutes',
    ];
  }


  private function acquireOrRefreshLock( &$ownerId ) {
    if ( $this->processingLockService->isLockedByOthers( $ownerId ) ) {
      return [
        'success' => false,
        'locked'  => true,
        'message' => 'Content stats processing is already running in another tab/session',
      ];
    }

    if ( ! $ownerId ) {
      $ownerId = $this->processingLockService->acquire();
      if ( ! $ownerId ) {
        return [
          'success' => false,
          'locked'  => true,
          'message' => 'Content stats processing is already running in another tab/session',
        ];
      }
    }

    $this->processingLockService->refresh( $ownerId );

    return null;
  }


  private function finalizeReportingCycle(): void {
    $this->retryService->reset();
    $this->lastSentService->update( time() );
    $this->contentStatsService->resetPostTypesStatsData();
    $this->processingLockService->release();
    $this->eventReasonService->clear();
    $this->contentChangeDetectionService->saveCurrentSnapshot();
  }


}
