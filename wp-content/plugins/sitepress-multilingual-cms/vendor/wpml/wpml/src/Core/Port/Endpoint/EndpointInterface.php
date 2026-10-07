<?php

namespace WPML\Core\Port\Endpoint;

interface EndpointInterface {


  public function handle( $requestData = null ): array;


}
