<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetPopulatedItemSections;

use WPML\Core\Component\Post\Application\Query\Criteria\SearchPopulatedTypesCriteriaBuilder;
use WPML\Core\Component\Post\Application\Query\SearchPopulatedTypesQueryInterface;
use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\PHP\Exception\Exception;
use WPML\PHP\Exception\InvalidArgumentException;

class GetPopulatedItemSectionsController implements EndpointInterface {

  private $searchPopulatedTypesQuery;

  private $populatedItemSectionsFilter;

  private $criteriaBuilder;


  public function __construct(
    SearchPopulatedTypesQueryInterface $searchPopulatedTypesQuery,
    PopulatedItemSectionsFilterInterface $populatedItemSectionsFilter,
    SearchPopulatedTypesCriteriaBuilder $criteriaBuilder
  ) {
    $this->searchPopulatedTypesQuery   = $searchPopulatedTypesQuery;
    $this->populatedItemSectionsFilter = $populatedItemSectionsFilter;
    $this->criteriaBuilder             = $criteriaBuilder;
  }


  public function handle( $requestData = null ): array {
    $requestData = $this->validateRequestData( $requestData ?? [] );

    try {
      $criteria = $this->criteriaBuilder->build( $requestData );
    } catch ( InvalidArgumentException $e ) {
      throw new InvalidArgumentException(
        'The request data for GetPopulatedItemSections is not valid.' . $e->getMessage()
      );
    }

    $itemSectionIds = $requestData['itemSectionIds'];

    $populatedPostItems = $this->searchPopulatedTypesQuery->get( $criteria );

    foreach ( $itemSectionIds as $key => $itemSectionId ) {
      if (
        strpos( $itemSectionId, 'post/' ) === 0 &&
        ! in_array( str_replace( 'post/', '', $itemSectionId ), $populatedPostItems )
      ) {
        unset( $itemSectionIds[ $key ] );
      }
    }

    $itemSectionIds = array_values( $itemSectionIds );

    $itemSectionIds = $this->populatedItemSectionsFilter->filter( $itemSectionIds, $criteria );

    return [
      'itemSectionIds' => $itemSectionIds,
    ];
  }


  private function validateRequestData( array $requestData ): array {
    if (
      ! isset( $requestData['itemSectionIds'] ) ||
      ! is_array( $requestData['itemSectionIds'] )
    ) {
      throw new InvalidArgumentException( 'itemSectionIds is required' );
    }

    foreach ( $requestData['itemSectionIds'] as $itemSectionId ) {
      if ( ! is_string( $itemSectionId ) ) {
        throw new InvalidArgumentException( 'All itemSectionIds must be numeric' );
      }
    }

    $validated = [
      'itemSectionIds' => array_map( 'strval', $requestData['itemSectionIds'] )
    ];

    if ( isset( $requestData['sourceLanguageCode'] ) && is_string( $requestData['sourceLanguageCode'] ) ) {
      $validated['sourceLanguageCode'] = $requestData['sourceLanguageCode'];
    }

    if ( isset( $requestData['targetLanguageCode'] ) && is_string( $requestData['targetLanguageCode'] ) ) {
      $validated['targetLanguageCode'] = $requestData['targetLanguageCode'];
    }

    if ( isset( $requestData['translationStatuses'] ) && is_array( $requestData['translationStatuses'] ) ) {
      foreach ( $requestData['translationStatuses'] as $translationStatus ) {
        if ( is_numeric( $translationStatus ) ) {
          $validated['translationStatuses'][] = (int) $translationStatus;
        }
      }
    }

    if ( isset( $requestData['publicationStatus'] ) && is_string( $requestData['publicationStatus'] ) ) {
      $validated['publicationStatus'] = $requestData['publicationStatus'];
    }

    return $validated;
  }


}
