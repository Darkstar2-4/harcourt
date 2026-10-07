<?php

namespace WPML\Core\Component\Translation\Application\Service;

use WPML\Core\Component\Translation\Application\Query\TranslationQueryInterface;
use WPML\Core\Component\Translation\Domain\CompletedTranslationDetector;


class CompletedTranslationService {

  private $completedTranslationDetector;

  private $translationsQuery;


  public function __construct(
    CompletedTranslationDetector $completedTranslationDetector,
    TranslationQueryInterface $translationsQuery
  ) {
    $this->completedTranslationDetector = $completedTranslationDetector;
    $this->translationsQuery            = $translationsQuery;
  }


  public function hasJobBeenCompletedBeforeResending( int $jobId ): bool {
    $translation = $this->translationsQuery->getOneByJobId( $jobId );
    if ( ! $translation ) {
      return false;
    }

    return $this->completedTranslationDetector->isTranslationCompleted(
      $translation->getStatus()->get(),
      $translation->needsUpdate(),
      $translation->getId(),
      $translation->getTranslatedElementId()
    );
  }


  public function isTranslationCompleted( $status, $needsUpdate, $translationId, $translatedElementId = null ) {
    return $this->completedTranslationDetector->isTranslationCompleted(
      $status,
      $needsUpdate,
      $translationId,
      $translatedElementId
    );
  }


}
