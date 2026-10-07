<?php

namespace WPML\Core\Component\Translation\Domain\TranslationMethod\Validator;

use WPML\Core\Component\Translation\Domain\TranslationMethod\TranslationServiceMethod;
use WPML\Core\SharedKernel\Component\TranslationProxy\Domain\Query\FetchRemoteTranslationServiceException;
use WPML\Core\SharedKernel\Component\TranslationProxy\Domain\Query\RemoteTranslationServiceQueryInterface;

class AssignedTranslationServiceValidator {

  private $remoteTranslationServiceQuery;


  public function __construct( RemoteTranslationServiceQueryInterface $remoteTranslationServiceQuery ) {
    $this->remoteTranslationServiceQuery = $remoteTranslationServiceQuery;
  }


  public function validate( array $translationMethods ): bool {
    if ( ! count( $translationMethods ) ) {
      return true;
    }

    $translationService = $this->remoteTranslationServiceQuery->getCurrent();

    $translationServiceActiveAndAuthenticated = $translationService
                                                && $translationService->isAuthenticated();

    if ( ! $translationServiceActiveAndAuthenticated ) {
      return false;
    }

    foreach ( $translationMethods as $translationServiceMethod ) {
      if ( ! $translationServiceMethod->getServiceId() ) {
        return false;
      }

      $sameTranslationServiceAssigned = $translationService->getId() ===
                                        $translationServiceMethod->getServiceId();

      if ( ! $sameTranslationServiceAssigned ) {
        return false;
      }
    }

    return true;
  }


}
