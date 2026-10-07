<?php

namespace WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config;

use WPML\UserInterface\Web\Core\SharedKernel\Config\Endpoint\Endpoint;

interface ApiInterface {


  public function registerRoute(
    Endpoint $endpoint,
    $handle,
    $authorisation
  );


  public function getFullUrl( Endpoint $endpoint ): string;


  public function nonce( $name = null ): string;


  public function validateRequest( string $capability ): bool;


  public function capabilityPlusAdmin( string $capability ): string;


  public function responseJsonSuccess( $data );


  public function responseJsonError( $data );


  public function responseJsonWithStatusCode( $data, $status_code );


  public function isRestRequest(): bool;


}
