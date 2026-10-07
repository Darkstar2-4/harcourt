<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetNeedsUpdateCreatedInCte;

use WPML\Core\Component\Translation\Application\Query\NeedsUpdateCreatedInCteQueryInterface;
use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\PHP\Exception\Exception;
use WPML\PHP\Exception\InvalidArgumentException;

class GetNeedsUpdateCreatedInCteController implements EndpointInterface {

  private $query;


  public function __construct( NeedsUpdateCreatedInCteQueryInterface $query ) {
    $this->query = $query;
  }


  public function handle( $requestData = null ): array {
    $count = $this->query->get();

    return [
      'success' => true,
      'data'    => $count
    ];
  }


}
