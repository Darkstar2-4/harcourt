<?php

namespace WPML\Infrastructure\WordPress\Component\Troubleshooting\TranslationTablesOptimization\Domain\PreviousState;

use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationDataService\QueryInterface;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\Port\Persistence\QueryPrepareInterface;

class Query implements QueryInterface {

  private $queryHandler;

  private $queryPrepare;


  public function __construct(
    QueryHandlerInterface $queryHandler,
    QueryPrepareInterface $queryPrepare
  ) {
    $this->queryHandler = $queryHandler;
    $this->queryPrepare = $queryPrepare;
  }


  public function countRemaining(): int {
    $statusTable = $this->queryPrepare->prefix() . 'icl_translation_status';
    $tmpTable    = $this->queryPrepare->prefix() . CompletedRecordsStorage::TMP_TABLE_NAME;

    $query = "
      SELECT COUNT(*)
      FROM {$statusTable} ts
      LEFT JOIN {$tmpTable} tp ON ts.translation_id = tp.translation_id
      WHERE ts._prevstate IS NOT NULL
      AND (tp.translation_id IS NULL OR tp.processed = 0)
    ";

    try {
      $count = $this->queryHandler->querySingle( $query );

      return (int) $count;
    } catch ( \Throwable $e ) {
      return 0;
    }
  }


  public function getRemaining( int $limit ): array {
    $statusTable = $this->queryPrepare->prefix() . 'icl_translation_status';
    $tmpTable    = $this->queryPrepare->prefix() . CompletedRecordsStorage::TMP_TABLE_NAME;

    $query = "
      SELECT 
        ts.translation_id as translationId,
        ts._prevstate as previousState
      FROM {$statusTable} ts
      LEFT JOIN {$tmpTable} tp ON ts.translation_id = tp.translation_id
      WHERE ts._prevstate IS NOT NULL
      AND (tp.translation_id IS NULL OR tp.processed = 0)
      LIMIT %d
    ";

    try {
      $preparedQuery = $this->queryPrepare->prepare( $query, $limit );

      $result = $this->queryHandler->query( $preparedQuery )->getResults();

      return $result;
    } catch ( \Throwable $e ) {
      return [];
    }
  }


}
