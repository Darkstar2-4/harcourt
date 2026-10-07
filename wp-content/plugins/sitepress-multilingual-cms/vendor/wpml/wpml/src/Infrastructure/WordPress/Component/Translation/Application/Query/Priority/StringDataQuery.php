<?php

namespace WPML\Infrastructure\WordPress\Component\Translation\Application\Query\Priority;

use WPML\Core\Component\Translation\Application\Query\Priority\StringDataQueryInterface;
use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\Port\Persistence\QueryPrepareInterface;

class StringDataQuery implements StringDataQueryInterface {

    private $queryHandler;

    private $queryPrepare;


  public function __construct(
        QueryHandlerInterface $queryHandler,
        QueryPrepareInterface $queryPrepare
    ) {
      $this->queryHandler = $queryHandler;
      $this->queryPrepare = $queryPrepare;
  }


  public function getStringsData( array $stringIds ): array {
    if ( empty( $stringIds ) ) {
        return [];
    }
      $placeholders = implode( ',', array_fill( 0, count( $stringIds ), '%d' ) );
      $sql          = "
			SELECT id, context, gettext_context
			FROM {$this->queryPrepare->prefix()}icl_strings
			WHERE id IN ($placeholders)
		";
      $sql          = $this->queryPrepare->prepare( $sql, ...$stringIds );
    try {
        $results = $this->queryHandler->query( $sql )->getResults();
    } catch ( DatabaseErrorException $e ) {
        return [];
    }
      $stringsData = [];
    foreach ( $results as $row ) {
        $stringsData[] = [
            'id'      => (int) $row['id'],
            'domain'  => $row['context'] ?: null,
            'context' => $row['gettext_context'] ?: null,
        ];
    }
      return $stringsData;
  }


}
