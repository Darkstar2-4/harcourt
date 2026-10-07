<?php

namespace WPML\Infrastructure\WordPress\Component\Item\Application\Query;

use WPML\Core\Component\Post\Application\Query\Criteria\SearchCriteria;
use WPML\Core\Component\Post\Application\Query\SearchQueryInterface;
use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\Port\Persistence\ResultCollectionInterface;
use WPML\Infrastructure\WordPress\Component\Item\Application\Query\SearchQuery\ItemWithTranslationStatusDtoMapper;
use WPML\Infrastructure\WordPress\Component\Item\Application\Query\SearchQuery\QueryBuilder\QueryBuilderResolver;
use WPML\Infrastructure\WordPress\Component\Item\Application\Query\SearchQuery\TranslationsQuery;
use WPML\PHP\Exception\InvalidArgumentException;

class SearchQuery implements SearchQueryInterface {

  private $queryBuilderResolver;

  private $queryHandler;

  private $mapper;

  private $translationsQuery;


  public function __construct(
    QueryBuilderResolver $queryBuilderResolver,
    QueryHandlerInterface $queryHandler,
    ItemWithTranslationStatusDtoMapper $mapper,
    TranslationsQuery $translationsQuery
  ) {
    $this->queryBuilderResolver = $queryBuilderResolver;
    $this->queryHandler         = $queryHandler;
    $this->mapper               = $mapper;
    $this->translationsQuery    = $translationsQuery;
  }


  public function get( SearchCriteria $criteria ) {
    $query = $this->queryBuilderResolver->resolveSearchQueryBuilder()->build( $criteria );

    $posts = $this->queryHandler->query( $query );

    $jobs = $this->translationsQuery->get( $posts, $criteria->getType(), $criteria->getSourceLanguageCode() );

    return $this->mapper->mapCollection( $posts, $jobs, $criteria );
  }


  public function count( SearchCriteria $criteria ): int {
    $query = $this->queryBuilderResolver->resolveSearchQueryBuilder()->buildCount( $criteria );

    $count = $this->queryHandler->querySingle( $query );

    return (int) $count;
  }


}
