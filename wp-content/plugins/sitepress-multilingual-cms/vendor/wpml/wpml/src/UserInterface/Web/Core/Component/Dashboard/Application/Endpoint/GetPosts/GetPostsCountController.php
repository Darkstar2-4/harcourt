<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetPosts;

use WPML\Core\Component\Post\Application\Query\Criteria\SearchCriteriaBuilder;
use WPML\Core\Component\Post\Application\Query\SearchQueryInterface;
use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\PHP\Exception\Exception;
use WPML\PHP\Exception\InvalidArgumentException;


class GetPostsCountController implements EndpointInterface {

  private $findBySearchCriteriaQuery;

  private $criteriaBuilder;


  public function __construct(
    SearchQueryInterface $findBySearchCriteriaQueryInterface,
    SearchCriteriaBuilder $criteriaBuilder
  ) {
    $this->findBySearchCriteriaQuery = $findBySearchCriteriaQueryInterface;
    $this->criteriaBuilder           = $criteriaBuilder;
  }


  public function handle( $requestData = null ): array {
    $requestData = $requestData ?: [];

    try {
      $criteria  = $this->criteriaBuilder->build( $requestData );

      return [
        'totalCount' => $this->findBySearchCriteriaQuery->count( $criteria )
      ];
    } catch ( InvalidArgumentException $e ) {
      throw new InvalidArgumentException(
        'The request data for GetPostsCount is not valid.' . $e->getMessage()
      );
    }
  }


}
