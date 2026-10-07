<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetTranslationStatus;

use WPML\Core\Component\Translation\Application\Query\Dto\TranslationStatusDto;
use WPML\Core\Component\Translation\Application\Query\TranslationStatusQueryInterface;
use WPML\Core\Port\Endpoint\EndpointInterface;


class GetTranslationStatusController implements EndpointInterface {

  private $translationStatusQuery;


  public function __construct( TranslationStatusQueryInterface $translationStatusQuery ) {
    $this->translationStatusQuery = $translationStatusQuery;
  }


  public function handle( $requestData = null ): array {
    $data = is_array( $requestData ) ? $requestData : [];
    $jobIds = array_filter( $data, 'is_numeric' );
    $jobIds = array_map( 'intval', array_values( $jobIds ) );
    $translations  = $this->translationStatusQuery->getByJobIds( $jobIds, true );

    return array_map(
      function ( TranslationStatusDto $translation ) {
        return $translation->toArray();
      },
      $translations
    );
  }


}
