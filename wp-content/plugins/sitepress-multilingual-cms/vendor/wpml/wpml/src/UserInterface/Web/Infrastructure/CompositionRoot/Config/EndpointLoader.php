<?php

namespace WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config;

use Throwable;
use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\DicInterface;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Endpoint\Endpoint;

class EndpointLoader {

  private $endpoint;

  private $dic;

  private $api;

  private $outputBufferActive = false;


  public function __construct(
    Endpoint $endpoint,
    DicInterface $dic,
    ApiInterface $api
  ) {
    $this->endpoint = $endpoint;
    $this->dic      = $dic;
    $this->api      = $api;
    $this->register();
  }


  public function register() {
    $this->api->registerRoute(
      $this->endpoint,
      [ $this, 'handle' ],
      [ $this, 'authorisation' ]
    );
  }


  public function handle( $params ) {
    $handlerString = $this->endpoint->handler();

    if ( ! $handlerString ) {
      $this->api->responseJsonError( 'Endpoint handler missing.' );

      return;
    }

    $handler = $this->dic->make( $handlerString );

    try {
      $this->outputBufferActive = ob_start();

      $result = $handler->handle( $params );

      $this->outputBufferActive && ob_end_clean();
      $this->outputBufferActive = false;

      if ( isset( $result['status'] ) ) {
        $status = $result['status'];
        if ( is_int( $status ) ) {
          unset( $result['status'] );
          return $this->api->responseJsonWithStatusCode( $result, $status );
        }
      }

      return $this->api->responseJsonSuccess( $result );
    } catch ( Throwable $e ) {
      $this->outputBufferActive && ob_end_clean();
      $this->outputBufferActive = false;

      return $this->api->responseJsonError( $e->getMessage() );
    }
  }


  public function authorisation() {
    return $this->api->validateRequest( $this->endpoint->capability() );
  }


}
