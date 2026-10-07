<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\SendToTranslation;

use WPML\Core\Component\Translation\Application\Service\Dto\SendToTranslationDto;
use WPML\Core\Component\Translation\Application\Service\TranslationService;
use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\PHP\Exception\Exception;
use WPML\PHP\Exception\InvalidArgumentException;

class SendToTranslationController implements EndpointInterface {

  private $translationService;


  public function __construct( TranslationService $translationService ) {
    $this->translationService = $translationService;
  }


  public function handle( $requestData = null ): array {
    $requestData = $requestData ?: [];

    try {
      $sendToTranslationDto = SendToTranslationDto::fromArray( $requestData );

      $result = $this->translationService->send( $sendToTranslationDto );

      return [
        'success' => true,
        'data'    => $result->toArray()
      ];
    } catch ( InvalidArgumentException $e ) {
      return [
        'success' => false,
        'data' => sprintf(
          __( 'The request data for SendToTranslation is not valid: %s', 'wpml' ),
          $e->getMessage()
        )
      ];
    } catch ( TranslationService\TranslationServiceException $e ) {
      return [
        'success' => false,
        'data'    => 'TranslationServiceException: ' . $e->getMessage()
      ];
    } catch ( Exception $e ) {
      return [
        'success' => false,
        'data'    => $e->getMessage()
      ];
    }
  }


}
