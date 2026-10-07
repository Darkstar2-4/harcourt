<?php

namespace WPML\Core\Component\ReportContentStats\Application\Service;

use WPML\Core\Component\ReportContentStats\Domain\Repository\DailyTranslationCountRepositoryInterface;
use WPML\Core\Component\ReportContentStats\Domain\Repository\LastTranslationCompletedRepositoryInterface;

class TranslationActivityService {

    const BREAK_THRESHOLD_DAYS  = 7;
    const LARGE_BATCH_THRESHOLD = 20;
    const SECONDS_IN_DAY        = 86400;

    private $resendTriggerService;

    private $lastTranslationCompletedRepository;

    private $dailyTranslationCountRepository;


  public function __construct(
        ResendTriggerService $resendTriggerService,
        LastTranslationCompletedRepositoryInterface $lastTranslationCompletedRepository,
        DailyTranslationCountRepositoryInterface $dailyTranslationCountRepository
    ) {
      $this->resendTriggerService               = $resendTriggerService;
      $this->lastTranslationCompletedRepository = $lastTranslationCompletedRepository;
      $this->dailyTranslationCountRepository    = $dailyTranslationCountRepository;
  }


  public function onTranslationCompleted() {
    $this->triggerIfTranslationAfterBreak();
    $this->triggerIfLargeBatch();
    $this->lastTranslationCompletedRepository->update( time() );
  }


  private function triggerIfTranslationAfterBreak() {
    $lastCompleted  = $this->lastTranslationCompletedRepository->get();
    $breakThreshold = self::BREAK_THRESHOLD_DAYS * self::SECONDS_IN_DAY;

    if ( $lastCompleted !== null && ( time() - $lastCompleted ) > $breakThreshold ) {
        $this->resendTriggerService->trigger( EventReasonService::REASON_TRANSLATION_AFTER_BREAK );
    }
  }


  private function triggerIfLargeBatch() {
    $this->dailyTranslationCountRepository->increment();

    if ( $this->dailyTranslationCountRepository->getCount() >= self::LARGE_BATCH_THRESHOLD ) {
        $this->resendTriggerService->trigger( EventReasonService::REASON_TRANSLATION_LARGE_BATCH );
    }
  }


}
