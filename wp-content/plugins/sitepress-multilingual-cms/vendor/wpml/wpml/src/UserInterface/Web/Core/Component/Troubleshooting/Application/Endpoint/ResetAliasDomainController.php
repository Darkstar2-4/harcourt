<?php

namespace WPML\UserInterface\Web\Core\Component\Troubleshooting\Application\Endpoint;

use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\TM\ATE\ClonedSites\SecondaryDomains;

class ResetAliasDomainController implements EndpointInterface {

  private $secondaryDomains;


  public function __construct( SecondaryDomains $secondaryDomains ) {
    $this->secondaryDomains = $secondaryDomains;
  }


  public function handle( $requestData = null ): array {
    $this->secondaryDomains->reset();

    return [ 'success' => true ];
  }


}
