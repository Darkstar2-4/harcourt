<?php

namespace WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config\Updates;

use WPML\PHP\Exception\Exception;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Endpoint\Endpoint;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Endpoint\MethodType;
use WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config\ApiInterface;

class UpdateHandler {
  const ROUTE_ID = 'updates';
  const ROUTE_PATH = '/updates';

  private $_endpoint;

  private $api;

  private $repository;

  private $updatesToPerform = [];


  public function __construct( ApiInterface $api, Repository $repository ) {
    $this->api = $api;
    $this->repository = $repository;
  }


  public function endpoint() {
    if ( $this->_endpoint === null ) {
      $this->_endpoint = new Endpoint( self::ROUTE_ID, self::ROUTE_PATH );
      $this->_endpoint->setMethod( MethodType::POST );
    }

    return $this->_endpoint;
  }


  public function registerRoute( $updatesToPerform ) {
    $this->updatesToPerform = $updatesToPerform;

    $this->api->registerRoute(
      $this->endpoint(),
      [ $this, 'handle' ],
      [ $this, 'authorisation' ]
    );
  }


  public function handle( $requestData ) {
    if (
      ! isset( $requestData['update'] )
      || ! isset( $this->updatesToPerform[ $requestData['update'] ] )
    ) {
      return;
    }

    $this->doUpdate( $this->updatesToPerform[ $requestData['update'] ] );
  }


  public function doUpdate( $update ) {
    try {
      $update->tryOnlyOnce()
        ? $this->repository->setUpdateTryOnlyOnceStuck( $update )
        : $this->repository->setUpdateInProgress( $update );

      $handler = $update->handler();
      $result = $handler->update();

      $result
        ? $this->repository->setUpdateComplete( $update )
        : ( $update->tryOnlyOnce()
          ? $this->repository->setUpdateTryOnlyOnceFailed( $update )
          : $this->repository->setUpdateFailed( $update ) );
    } catch ( Exception $e ) {
        $update->tryOnlyOnce()
          ? $this->repository->setUpdateTryOnlyOnceFailed( $update )
          : $this->repository->setUpdateFailed( $update );
    }
  }


  public function authorisation() {
    return $this->api->validateRequest( $this->endpoint()->capability() );
  }


}
