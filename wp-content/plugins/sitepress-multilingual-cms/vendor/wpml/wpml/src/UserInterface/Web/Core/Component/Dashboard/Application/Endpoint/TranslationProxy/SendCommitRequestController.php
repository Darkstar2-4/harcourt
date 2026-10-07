<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\TranslationProxy;

use WPML\Core\Component\TranslationProxy\Application\Service\SendTranslationProxyCommitRequestException;
use WPML\Core\Component\TranslationProxy\Application\Service\TranslationProxyServiceInterface;
use WPML\Core\Port\Endpoint\EndpointInterface;

class SendCommitRequestController implements EndpointInterface {

  private $translationProxyService;


  public function __construct( TranslationProxyServiceInterface $translationProxyService ) {
    $this->translationProxyService = $translationProxyService;
  }


  public function handle( $requestData = null ): array {
    try {
      return [
        'batchJobId' => $this->translationProxyService->sendCommitRequest(),
      ];
    } catch ( SendTranslationProxyCommitRequestException $e ) {
      return [
        'batchJobId' => false,
      ];
    }
  }


}
