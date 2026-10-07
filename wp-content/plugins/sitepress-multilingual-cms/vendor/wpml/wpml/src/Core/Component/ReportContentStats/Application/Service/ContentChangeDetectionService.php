<?php

namespace WPML\Core\Component\ReportContentStats\Application\Service;

use WPML\Core\Component\ReportContentStats\Domain\Query\PublishedPostCountQueryInterface;
use WPML\Core\Component\ReportContentStats\Domain\Repository\ContentSnapshotRepositoryInterface;

class ContentChangeDetectionService {

  const PERCENTAGE_THRESHOLD         = 0.20;
  const ABSOLUTE_THRESHOLD           = 50;
  const MIN_BASELINE_FOR_PERCENTAGE  = 50;

  private $resendTriggerService;

  private $snapshotRepository;

  private $postCountQuery;


  public function __construct(
    ResendTriggerService $resendTriggerService,
    ContentSnapshotRepositoryInterface $snapshotRepository,
    PublishedPostCountQueryInterface $postCountQuery
  ) {
    $this->resendTriggerService = $resendTriggerService;
    $this->snapshotRepository   = $snapshotRepository;
    $this->postCountQuery       = $postCountQuery;
  }


  public function saveCurrentSnapshot(): void {
    $this->snapshotRepository->save( $this->postCountQuery->get() );
  }


  public function check(): void {
    $baseline = $this->snapshotRepository->get();

    if ( $baseline === null ) {
      return;
    }

    $current = $this->postCountQuery->get();
    $diff    = abs( $current - $baseline );

    if ( $this->isSignificantChange( $baseline, $diff ) ) {
      $this->resendTriggerService->trigger( EventReasonService::REASON_CONTENT_CHANGE );
      $this->snapshotRepository->save( $current );
    }
  }


  private function isSignificantChange( int $baseline, int $diff ): bool {
    if ( $diff >= self::ABSOLUTE_THRESHOLD ) {
      return true;
    }

    $isPercentageApplicable = $baseline > self::MIN_BASELINE_FOR_PERCENTAGE;

    return $isPercentageApplicable && ( $diff / $baseline ) >= self::PERCENTAGE_THRESHOLD;
  }


}
