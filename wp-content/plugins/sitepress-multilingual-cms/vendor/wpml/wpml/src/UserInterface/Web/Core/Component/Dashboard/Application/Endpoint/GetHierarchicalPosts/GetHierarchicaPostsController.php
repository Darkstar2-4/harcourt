<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetHierarchicalPosts;

use WPML\Core\Component\Post\Application\Query\Criteria\HierarchicalPostCriteria;
use WPML\Core\Component\Post\Application\Query\Dto\HierarchicalPostDto;
use WPML\Core\Component\Post\Application\Query\HierarchicalPostQueryInterface;
use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\PHP\Exception\Exception;
use WPML\PHP\Exception\InvalidArgumentException;


class GetHierarchicaPostsController implements EndpointInterface {

  private $hierarchicalItemsQuery;


  public function __construct(
    HierarchicalPostQueryInterface $hierarchicalItemsQuery
  ) {
    $this->hierarchicalItemsQuery = $hierarchicalItemsQuery;
  }


  public function handle( $requestData = null ): array {
    $requestData = $requestData ?: [];

    try {
      $criteria = HierarchicalPostCriteria::fromArray( $requestData );
      $hierarchicalItems = $this->hierarchicalItemsQuery->getMany( $criteria );
    } catch ( InvalidArgumentException $e ) {
      throw new InvalidArgumentException(
        'The request data for GetHierarchicalPosts is not valid.'
      );
    }
    return array_map(
      function ( HierarchicalPostDto $page ) {
        return [
          'id' => $page->getId(),
          'title' => $page->getTitle(),
          'parentId' => $page->getParentId(),
        ];
      },
      $hierarchicalItems
    );
  }


}
