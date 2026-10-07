<?php

namespace WPML\Core\Component\ATE\Domain\Credits;

use WPML\Core\Component\ATE\Domain\Credits\Repository\CreditsInProgressRepositoryInterface;
use WPML\Core\SharedKernel\Component\Translation\Domain\TranslationStatus;

class CreditsInProgress {

  private $creditsInProgressRepository;


  public function __construct( CreditsInProgressRepositoryInterface $creditsInProgressRepository ) {
    $this->creditsInProgressRepository = $creditsInProgressRepository;
  }


  public function getCount() {
    return new CreditsInProgressDTO(
      $this->creditsInProgressRepository->getCreditsInProgressCount(
        $this->statusesToConsider()
      )
    );
  }


  private function statusesToConsider() {
    return [
      TranslationStatus::IN_PROGRESS,
      TranslationStatus::ATE_NEEDS_RETRY
    ];
  }


}
