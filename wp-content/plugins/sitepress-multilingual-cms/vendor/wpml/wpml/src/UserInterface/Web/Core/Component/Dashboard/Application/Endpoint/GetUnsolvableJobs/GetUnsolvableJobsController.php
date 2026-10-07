<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetUnsolvableJobs;

use WPML\Core\Component\Translation\Application\Query\UnsolvableJobsQueryInterface;
use WPML\Core\Port\Endpoint\EndpointInterface;

class GetUnsolvableJobsController implements EndpointInterface {

    private $query;


  public function __construct( UnsolvableJobsQueryInterface $query ) {
      $this->query = $query;
  }


  public function handle( $requestData = null ): array {
      return [
          'jobs' => $this->query->getUnsolvableJobs()
      ];
  }


}
