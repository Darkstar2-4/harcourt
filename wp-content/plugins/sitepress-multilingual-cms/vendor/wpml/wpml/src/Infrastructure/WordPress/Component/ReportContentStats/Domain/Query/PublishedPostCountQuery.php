<?php

namespace WPML\Infrastructure\WordPress\Component\ReportContentStats\Domain\Query;

use WPML\Core\Component\ReportContentStats\Application\Query\ContentStatsTranslatableTypesQueryInterface;
use WPML\Core\Component\ReportContentStats\Domain\Query\PublishedPostCountQueryInterface;
use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\Port\Persistence\QueryPrepareInterface;

class PublishedPostCountQuery implements PublishedPostCountQueryInterface {

  private $queryHandler;

  private $queryPreparer;

  private $translatableTypesQuery;


  public function __construct(
    QueryHandlerInterface $queryHandler,
    QueryPrepareInterface $queryPreparer,
    ContentStatsTranslatableTypesQueryInterface $translatableTypesQuery
  ) {
    $this->queryHandler           = $queryHandler;
    $this->queryPreparer          = $queryPreparer;
    $this->translatableTypesQuery = $translatableTypesQuery;
  }


  public function get(): int {
    $slugs = $this->getTranslatablePostTypeSlugs();

    if ( empty( $slugs ) ) {
      return 0;
    }

    $inClause = $this->queryPreparer->prepareIn( $slugs );

    $sql = "SELECT COUNT(*)
            FROM {$this->queryPreparer->prefix()}posts
            WHERE post_status = 'publish'
              AND post_type IN ({$inClause})";

    try {
      $count = $this->queryHandler->querySingle( $sql );

      return $count !== null ? intval( $count ) : 0;
    } catch ( DatabaseErrorException $e ) {
      return 0;
    }
  }


  private function getTranslatablePostTypeSlugs(): array {
    $types = array_filter(
      $this->translatableTypesQuery->getTranslatable(),
      fn( $type ) => $type->getId() !== 'attachment'
    );

    return array_map(
      fn( $type ) => $type->getId(),
      $types
    );
  }


}
