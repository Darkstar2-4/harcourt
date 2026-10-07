<?php

namespace WPML\Core\Component\Translation\Domain;

use WPML\Core\Component\Translation\Domain\PreviousState\PreviousStateQueryInterface;
use WPML\Core\SharedKernel\Component\Translation\Domain\TranslationStatus;


class CompletedTranslationDetector {

  private $previousStateQuery;


  public function __construct( PreviousStateQueryInterface $previousStateQuery ) {
    $this->previousStateQuery = $previousStateQuery;
  }


  public function isTranslationCompleted(
    $status,
    $needsUpdate,
    $translationId,
    $translatedElementId = null
  ) {
    $isCompletedTranslationWhichDoesNotNeedUpdate =
      $status === TranslationStatus::COMPLETE && ! $needsUpdate;

    if ( $isCompletedTranslationWhichDoesNotNeedUpdate ) {
      return true;
    }

    $inProgressStatuses = [
      TranslationStatus::IN_PROGRESS,
      TranslationStatus::WAITING_FOR_TRANSLATOR
    ];
    if (
      in_array( $status, $inProgressStatuses, true ) &&
      $translatedElementId
    ) {
      $previousState = $this->previousStateQuery->getByTranslationId( $translationId );
      if (
        ! $previousState ||
        ( $previousState->getStatus()->get() === TranslationStatus::COMPLETE && ! $previousState->getNeedsUpdate() )
      ) {
        return true;
      }
    }

    return false;
  }


}
