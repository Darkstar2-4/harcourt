<?php

namespace WPML\Core\Component\TranslationProxy\Application\Service;

use WPML\Core\SharedKernel\Component\TranslationProxy\Domain\Query\FetchRemoteTranslationServiceException;
use WPML\Core\SharedKernel\Component\TranslationProxy\Domain\Query\RemoteTranslationServiceQueryInterface;
use WPML\Core\SharedKernel\Component\TranslationProxy\Domain\RemoteTranslationServiceDomain;

class RemoteTranslationService {

  private $remoteTranslationServiceQuery;


  public function __construct( RemoteTranslationServiceQueryInterface $remoteTranslationServiceQuery ) {
    $this->remoteTranslationServiceQuery = $remoteTranslationServiceQuery;
  }


  public function getCurrent( bool $forceRefreshExtraFields = false ) {
    return $this->remoteTranslationServiceQuery->getCurrent( $forceRefreshExtraFields );
  }


}
