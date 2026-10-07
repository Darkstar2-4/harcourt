<?php

namespace WPML\Legacy\Component\Post\Application\Query;

use WPML\Core\Component\Post\Application\Query\Criteria\HierarchicalPostCriteria;
use WPML\Core\Component\Post\Application\Query\Dto\HierarchicalPostDto;
use WPML\Core\Component\Post\Application\Query\HierarchicalPostQueryInterface;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\Port\Persistence\QueryPrepareInterface;

class HierarchicalPostQuery implements HierarchicalPostQueryInterface {

  private $queryPrepare;

  private $queryHandler;


  public function __construct(
    QueryPrepareInterface $queryPrepare,
    QueryHandlerInterface $queryHandler
  ) {
    $this->queryPrepare = $queryPrepare;
    $this->queryHandler = $queryHandler;
  }


  public function getMany( HierarchicalPostCriteria $criteria ) {
    $query = $this->prepareQuery( $criteria );

    $wpPages = $this->queryHandler->query( $query )->getResults();

    if ( empty( $wpPages ) ) {
      return [];
    }

    return array_map(
      function( $wpPage ) {
        return new HierarchicalPostDto(
          $wpPage['ID'],
          $wpPage['post_title'],
          $wpPage['post_parent']
        );
      },
      $wpPages
    );
  }


  private function prepareQuery( HierarchicalPostCriteria $criteria ) {
    $prefix = $this->queryPrepare->prefix();
    $query = "
      SELECT
        DISTINCT wpp.ID,
        wpp.post_title,
        wpp.post_parent
      FROM
        {$prefix}posts as wpp
      INNER JOIN {$prefix}posts AS wparent
        ON wparent.post_parent = wpp.ID
      INNER JOIN {$prefix}icl_translations AS wtr
        ON wtr.element_id = wpp.ID
      WHERE
        wpp.post_type = %s
        AND wparent.post_type = %s
        AND wtr.language_code = %s
        AND wtr.element_type = %s
    ";

    $element_type = sprintf( 'post_%s', $criteria->getType() );

    $basePart = $this->queryPrepare->prepare(
      $query,
      $criteria->getType(),
      $criteria->getType(),
      $criteria->getSourceLanguageCode(),
      $element_type
    );

    $searchPart = $criteria->getSearch() ?
      $this->queryPrepare->prepare( 'AND wpp.post_title LIKE %s', '%' . $criteria->getSearch() . '%' ) : '';

    $limitPart = $criteria->getLimit() > 0 ?
      $this->queryPrepare->prepare( 'LIMIT %d', $criteria->getLimit() ) : '';

    $offsetPart = $criteria->getOffset() > 0 ?
      $this->queryPrepare->prepare( 'OFFSET %d', $criteria->getOffset() ) : '';

    return $basePart . $searchPart . $limitPart . $offsetPart;
  }


}
