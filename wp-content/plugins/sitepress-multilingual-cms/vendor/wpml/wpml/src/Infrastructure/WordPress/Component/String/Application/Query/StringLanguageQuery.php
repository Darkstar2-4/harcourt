<?php

namespace WPML\Infrastructure\WordPress\Component\String\Application\Query;

use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\Port\Persistence\QueryPrepareInterface;
use WPML\Core\SharedKernel\Component\String\Application\Query\StringLanguageQueryInterface;


class StringLanguageQuery implements StringLanguageQueryInterface {

  private $queryHandler;

  private $queryPrepare;


  public function __construct(
    QueryHandlerInterface $queryHandler,
    QueryPrepareInterface $queryPrepare
  ) {
    $this->queryHandler = $queryHandler;
    $this->queryPrepare = $queryPrepare;
  }


  public function getStringLanguages( array $strings ): array {
    if ( ! $strings ) {
      return [];
    }

    $strings = array_map( 'intval', $strings );

    $sql = "
      SELECT
        s.id as stringId,
        s.language
      FROM {$this->queryPrepare->prefix()}icl_strings s
      WHERE s.id IN (" . implode( ',', $strings ) . ")
    ";

    try {
      $rowset = $this->queryHandler->query( $sql )->getResults();
    } catch ( DatabaseErrorException $e ) {
      $rowset = [];
    }

    return array_reduce(
      $rowset,
      function ( $carry, $row ) {
        $carry[ $row['stringId'] ] = $row['language'];

        return $carry;
      },
      []
    );
  }


}
