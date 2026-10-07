<?php

namespace WPML\UserInterface\Web\Core\Component\Communication\Application\Endpoint;

use WPML\Core\Component\Communication\Application\Query\DismissedNoticesQuery;
use WPML\Core\Port\Endpoint\EndpointInterface;

class GetDismissedNoticesController implements EndpointInterface {

  private $query;


  public function __construct( DismissedNoticesQuery $query ) {
    $this->query = $query;
  }


  public function handle( $requestData = null ): array {
    $notices = isset( $requestData['notices'] ) && is_array( $requestData['notices'] ) ? $requestData['notices'] : [];

    $sanitize = function ( string $notice ): string {
      return htmlspecialchars( strip_tags( $notice ) );
    };
    $notices  = array_map( $sanitize, $notices );

    return [
      'success' => true,
      'data'    => $this->query->getDismissed( $notices ),
    ];
  }


}
