<?php

namespace WPML\Infrastructure\WordPress\Component\String\Repository;

use WPML\Core\Port\Persistence\DatabaseWriteInterface;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\Port\Persistence\QueryPrepareInterface;
use WPML\Core\SharedKernel\Component\String\Application\Repository\StringBatchRepositoryInterface;
use WPML\Core\SharedKernel\Component\String\Domain\StringTranslation;

class StringBatchRepository implements StringBatchRepositoryInterface {

  private $databaseWrite;

  private $queryHandler;

  private $queryPrepare;


  public function __construct(
    DatabaseWriteInterface $databaseWrite,
    QueryHandlerInterface $queryHandler,
    QueryPrepareInterface $queryPrepare
  ) {
    $this->databaseWrite = $databaseWrite;
    $this->queryHandler  = $queryHandler;
    $this->queryPrepare  = $queryPrepare;
  }


  public function getStringTranslationsByBatch( int $batchId, string $targetLanguage ): array {
    $sql = $this->queryPrepare->prepare(
      "SELECT st.id, st.string_id, st.value, st.mo_string, st.language
       FROM {$this->queryPrepare->prefix()}icl_string_translations st
       INNER JOIN {$this->queryPrepare->prefix()}icl_string_batches sb ON st.string_id = sb.string_id
       WHERE sb.batch_id = %d AND st.language = %s",
      $batchId,
      $targetLanguage
    );

    $results = $this->queryHandler->query( $sql )->getResults();

    $stringTranslations = [];
    foreach ( $results as $row ) {
      $stringTranslations[] = new StringTranslation(
        (int) $row['id'],
        (int) $row['string_id'],
        ! empty( $row['value'] ) ? $row['value'] : null,
        ! empty( $row['mo_string'] ) ? $row['mo_string'] : null,
        $row['language']
      );
    }

    return $stringTranslations;
  }


  public function deleteStringTranslationsByIds( array $translationIds ): int {
    if ( empty( $translationIds ) ) {
      return 0;
    }

    $sql = $this->queryPrepare->prepare(
      "DELETE FROM {$this->queryPrepare->prefix()}icl_string_translations
       WHERE id IN (" . $this->queryPrepare->prepareIn( $translationIds ) . ")"
    );

    $result = $this->queryHandler->query( $sql );

    return $result->count();
  }


  public function updateStringTranslationsStatus( array $translationIds, int $status ): int {
    if ( empty( $translationIds ) ) {
      return 0;
    }

    $sql = $this->queryPrepare->prepare(
      "UPDATE {$this->queryPrepare->prefix()}icl_string_translations
       SET status = %d
       WHERE id IN (" . $this->queryPrepare->prepareIn( $translationIds ) . ")",
      $status
    );

    $result = $this->queryHandler->query( $sql );

    return $result->count();
  }


  public function deleteStringBatch( int $batchId ): int {
    return $this->databaseWrite->delete(
      'icl_string_batches',
      [ 'batch_id' => $batchId ]
    );
  }


}
