<?php

namespace WPML\UserInterface\Web\Core\Component\WpmlProxy\Application\Endpoint\SetWpmlProxyStatus;

use Throwable;
use WPML\Core\Component\WpmlProxy\Application\Exception\WpmlProxyException;
use WPML\Core\Component\WpmlProxy\Application\Service\WpmlProxyService;
use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\PHP\Exception\InvalidArgumentException;

class SetWpmlProxyStatusController implements EndpointInterface {

  private $wpmlProxyService;


  public function __construct( WpmlProxyService $wpmlProxyService ) {
    $this->wpmlProxyService = $wpmlProxyService;
  }


  public function handle( $requestData = null ): array {
    try {
      $enabled = $this->validateRequest( $requestData );
      $this->updateProxyStatus( $enabled );

      return [
        'success' => true,
        'data'    => [
          'enabled' => $this->wpmlProxyService->isEnabled(),
        ],
      ];
    } catch ( InvalidArgumentException $e ) {
      return [
        'success' => false,
        'message' => $e->getMessage(),
        'status'  => 400,
      ];
    } catch ( Throwable $e ) {
      return [
        'success' => false,
        'message' => $e->getMessage(),
        'status'  => 403,
      ];
    }
  }


  private function validateRequest( $requestData ): bool {
    if ( ! is_array( $requestData )
         || ! array_key_exists( 'enabled', $requestData )
    ) {
      throw new InvalidArgumentException( 'Missing required parameter: enabled' );
    }

    $enabled = $requestData['enabled'];

    if ( ! is_bool( $enabled ) ) {
      throw new InvalidArgumentException( 'Parameter "enabled" must be a boolean value' );
    }

    return $enabled;
  }


  private function updateProxyStatus( bool $enabled ) {
    if ( $enabled ) {
      $this->wpmlProxyService->enable();
    } else {
      $this->wpmlProxyService->disable();
    }
  }


}
