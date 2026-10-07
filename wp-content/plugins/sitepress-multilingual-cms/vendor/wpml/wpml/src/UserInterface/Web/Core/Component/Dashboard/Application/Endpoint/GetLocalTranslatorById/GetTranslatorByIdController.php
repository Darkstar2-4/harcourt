<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetLocalTranslatorById;

use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\Core\SharedKernel\Component\Translator\Application\Service\TranslatorsService;

class GetTranslatorByIdController implements EndpointInterface {

  private $translatorsService;


  public function __construct( TranslatorsService $translatorsService ) {
    $this->translatorsService = $translatorsService;
  }


  public function handle( $requestData = null ): array {
    $translatorId = $requestData['translatorId'] ?? null;

    if ( ! $translatorId ) {
      return [ 'translator' => null ];
    }

    $translator = $this->translatorsService->getById( $translatorId );

    return $translator ?
      [ 'translator' => $translator->toArray() ] :
      [ 'translator' => null ];
  }


}
