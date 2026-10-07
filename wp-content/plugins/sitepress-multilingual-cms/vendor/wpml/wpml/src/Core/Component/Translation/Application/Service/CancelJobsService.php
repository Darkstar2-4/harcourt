<?php

namespace WPML\Core\Component\Translation\Application\Service;

use WPML\Core\Component\Translation\Application\Event\JobsCancelledEvent;
use WPML\Core\Component\Translation\Application\Query\TranslationQueryInterface;
use WPML\Core\Component\Translation\Application\Repository\TranslationRepositoryInterface;
use WPML\Core\Component\Translation\Application\Service\PreviousState\PreviousStateService;
use WPML\Core\Component\Translation\Domain\Translation;
use WPML\Core\Component\Translation\Domain\TranslationEditor\AteEditor;
use WPML\Core\Component\Translation\Domain\TranslationType;
use WPML\Core\Port\Event\DispatcherInterface;
use WPML\Core\SharedKernel\Component\String\Application\Service\StringBatchCleanupService;
use WPML\Core\SharedKernel\Component\Translation\Domain\TranslationStatus;

class CancelJobsService {

  private $translationQuery;

  private $translationRepository;

  private $previousStateService;

  private $eventDispatcher;

  private $stringBatchCleanupService;


  public function __construct(
    TranslationQueryInterface $translationQuery,
    TranslationRepositoryInterface $translationRepository,
    PreviousStateService $previousStateService,
    DispatcherInterface $eventDispatcher,
    StringBatchCleanupService $stringBatchCleanupService
  ) {
    $this->translationQuery            = $translationQuery;
    $this->translationRepository       = $translationRepository;
    $this->previousStateService        = $previousStateService;
    $this->eventDispatcher             = $eventDispatcher;
    $this->stringBatchCleanupService   = $stringBatchCleanupService;
  }


  public function cancelJobs( array $jobIds ): array {
    if ( empty( $jobIds ) ) {
      return [
        'cancelledJobIds'  => [],
        'restoredStatuses' => [],
      ];
    }

    $cancelledJobIds  = [];
    $restoredStatuses = [];
    $jobDataForEvent  = [];

    foreach ( $jobIds as $jobId ) {
      $translation = $this->translationQuery->getOneByJobId( $jobId );
      if ( ! $translation ) {
        continue;
      }

      $translationType = $translation->getType()->get();

      $previousState = $this->previousStateService->get( $translation->getId() );

      if ( $previousState === null ) {
        $this->translationRepository->setCancelledStatus( $translation->getId() );
        $restoredStatuses[ $jobId ] = TranslationStatus::NOT_TRANSLATED;
      } else {
        $restoredStatuses[ $jobId ] = $this->determineRestoredStatus( $previousState );

        $this->previousStateService->revertToPreviousState( $translation->getId() );
      }

      if ( $translationType === TranslationType::STRING_BATCH ) {
        $this->stringBatchCleanupService->cleanupBatch(
          $translation->getOriginalElementId(),
          $translation->getTargetLanguageCode()
        );
      }

      $cancelledJobIds[] = $jobId;
      $jobDataForEvent[] = $this->buildJobDataForEvent( $translation );
    }

    if ( ! empty( $jobDataForEvent ) ) {
      $this->eventDispatcher->dispatch(
        new JobsCancelledEvent( $jobDataForEvent )
      );
    }

    return [
      'cancelledJobIds'  => $cancelledJobIds,
      'restoredStatuses' => $restoredStatuses,
    ];
  }


  public function cancelJobsInBatch( int $batchId ): array {
    $jobIds = $this->translationQuery->getJobIdsByBatchId( $batchId );

    if ( empty( $jobIds ) ) {
      return [
        'cancelledJobIds'  => [],
        'restoredStatuses' => [],
      ];
    }

    return $this->cancelJobs( $jobIds );
  }


  private function buildJobDataForEvent( Translation $translation ) {
    $job = $translation->getJob();

    $jobData = (object) [];
    $jobData->job_id        = $job ? $job->getId() : null;
    $jobData->editor        = null;
    $jobData->editor_job_id = null;

    if ( $job ) {
      $editor          = $job->getEditor();
      $jobData->editor = $editor->get();

      if ( $editor instanceof AteEditor ) {
        $jobData->editor_job_id = $editor->getEditorJobId();
      }
    }

    return $jobData;
  }


  private function determineRestoredStatus( ?array $previousState = null ): int {
    if ( ! $previousState ) {
      return TranslationStatus::NOT_TRANSLATED;
    }

    if ( ! empty( $previousState['needs_update'] ) ) {
      return TranslationStatus::NEEDS_UPDATE;
    }

    return $previousState['status'];
  }


}
