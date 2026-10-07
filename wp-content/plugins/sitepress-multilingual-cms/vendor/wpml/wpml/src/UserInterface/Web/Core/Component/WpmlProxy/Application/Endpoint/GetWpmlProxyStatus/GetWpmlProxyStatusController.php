<?php

namespace WPML\UserInterface\Web\Core\Component\WpmlProxy\Application\Endpoint\GetWpmlProxyStatus;

use WPML\Core\Component\WpmlProxy\Application\Service\WpmlProxyService;
use WPML\Core\Port\Endpoint\EndpointInterface;

class GetWpmlProxyStatusController implements EndpointInterface {

  private $wpmlProxyService;


  public function __construct( WpmlProxyService $wpmlProxyService ) {
    $this->wpmlProxyService = $wpmlProxyService;
  }


  public function handle( $requestData = null ): array {
    return [
      'success' => true,
      'data'    => [
        'enabled' => $this->wpmlProxyService->isEnabled(),
      ],
    ];
  }


}
