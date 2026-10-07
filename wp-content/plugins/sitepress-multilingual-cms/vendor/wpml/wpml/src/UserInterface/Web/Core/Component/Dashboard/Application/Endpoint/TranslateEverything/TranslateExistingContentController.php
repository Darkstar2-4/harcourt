<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\TranslateEverything;

use WPML\Core\Component\Translation\Application\Service\TranslateExistingContentService;
use WPML\Core\Port\Endpoint\EndpointInterface;

class TranslateExistingContentController implements EndpointInterface {

  private $service;


  public function __construct( TranslateExistingContentService $service ) {
    $this->service = $service;
  }


  public function handle( $requestData = null ): array {
    $postTypes    = $requestData['postTypes'] ?? [];
    $packageTypes = $requestData['packageTypes'] ?? [];

    $sanitize     = function ( string $type ): string {
      return htmlspecialchars( strip_tags( $type ) );
    };
    $postTypes    = array_map( $sanitize, $postTypes );
    $packageTypes = array_map( $sanitize, $packageTypes );

    $this->service->handle( $postTypes, $packageTypes );

    return [
      'success' => true,
    ];
  }


}
