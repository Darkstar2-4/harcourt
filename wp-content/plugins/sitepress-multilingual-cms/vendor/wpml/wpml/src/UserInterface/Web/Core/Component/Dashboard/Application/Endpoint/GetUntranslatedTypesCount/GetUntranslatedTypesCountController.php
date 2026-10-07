<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetUntranslatedTypesCount;

use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\Core\SharedKernel\Component\Item\Application\Service\UntranslatedService;

class GetUntranslatedTypesCountController implements EndpointInterface {

  private $service;


  public function __construct( UntranslatedService $service ) {
    $this->service = $service;
  }


  public function handle( $requestData = null ): array {
    $counts = $this->service->getUntranslatedTypesCounts();

    return array_map(
      function ( $count ) {
        return $count->toArray();
      },
      $counts
    );
  }


}
