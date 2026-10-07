<?php

namespace WPML\Infrastructure\WordPress\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationStatus;

use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationStatus\PreliminaryConditionQueryInterface;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\Port\Persistence\QueryPrepareInterface;

class PreliminaryConditionQuery implements PreliminaryConditionQueryInterface {

  private $queryHandler;

  private $queryPrepare;


  public function __construct(
    QueryHandlerInterface $queryHandler,
    QueryPrepareInterface $queryPrepare
  ) {
    $this->queryHandler = $queryHandler;
    $this->queryPrepare = $queryPrepare;
  }


  public function hasNonNullTranslationPackages(): bool {
    $table = $this->queryPrepare->prefix() . 'icl_translation_status';
    $query = "SELECT 1 FROM {$table} WHERE translation_package IS NOT NULL LIMIT 1";

    try {
      $result = $this->queryHandler->querySingle( $query );
      return $result !== null;
    } catch ( \Throwable $e ) {
      return false;
    }
  }


}
