<?php

namespace WPML\UserInterface\Web\Core\Component\PostHog\Application\Endpoint\Refresh;

use WPML\Core\Component\PostHog\Application\Service\PostHogRemoteRefreshService;
use WPML\Core\Port\Endpoint\EndpointInterface;

class RefreshPostHogCacheController implements EndpointInterface {

  private $remoteRefreshService;


  public function __construct( PostHogRemoteRefreshService $remoteRefreshService ) {
    $this->remoteRefreshService = $remoteRefreshService;
  }


  public function handle( $requestData = null ): array {
    if (
      ! is_array( $requestData ) ||
      ! isset( $requestData['timestamp'], $requestData['signature'] ) ||
      ! is_int( $requestData['timestamp'] ) ||
      ! is_string( $requestData['signature'] )
    ) {
      return [ 'success' => false, 'error' => 'invalid_request' ];
    }

    return $this->remoteRefreshService->refresh(
      $requestData['timestamp'],
      $requestData['signature']
    );
  }


}
