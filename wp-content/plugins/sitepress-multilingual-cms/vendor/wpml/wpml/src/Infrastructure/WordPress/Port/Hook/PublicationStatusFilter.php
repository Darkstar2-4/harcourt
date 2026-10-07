<?php
namespace WPML\Infrastructure\WordPress\Port\Hook;

use WPML\Core\Component\Post\Application\Query\Dto\PublicationStatusDto;

abstract class PublicationStatusFilter {

  const NAME = 'wpml_publication_status_dto_filter';


  public function filterByDto( array $publicationStatusDtos ) {
    $postStatuses = array_reduce(
      $publicationStatusDtos,
      function ( array $carry, PublicationStatusDto $publicationStatus ) {
        $carry[ $publicationStatus->getId() ] = $publicationStatus->getLabel();
        return $carry;
      },
      []
    );

    $postStatuses = apply_filters( static::NAME, $postStatuses );

    $publicationStatusDtos = [];
    foreach ( $postStatuses as $key => $value ) {
      $publicationStatusDtos[] = new PublicationStatusDto( $key, $value );
    }
    return $publicationStatusDtos;
  }


}
