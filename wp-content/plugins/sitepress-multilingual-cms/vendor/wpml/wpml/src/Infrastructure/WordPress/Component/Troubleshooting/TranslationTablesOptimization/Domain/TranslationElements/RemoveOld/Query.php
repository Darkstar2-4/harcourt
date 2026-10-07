<?php

namespace WPML\Infrastructure\WordPress\Component\Troubleshooting\TranslationTablesOptimization\Domain\TranslationElements\RemoveOld;

use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationDataService\QueryInterface;

class Query implements QueryInterface {

  private $wpdb;


  public function __construct( $wpdb ) {
    $this->wpdb = $wpdb;
  }


  public function countRemaining(): int {
    $jobTable = $this->wpdb->prefix . 'icl_translate_job';
    $tmpTable = $this->wpdb->prefix . CompletedRecordsStorage::TMP_TABLE_NAME;

    $sql = $this->wpdb->prepare(
      'SELECT COUNT(DISTINCT j.rid)
      FROM %i j
      LEFT JOIN %i tp ON j.rid = tp.rid
      WHERE j.translated = 1
      AND (tp.rid IS NULL OR tp.processed = 0)',
      $jobTable,
      $tmpTable
    );

    $count = $this->wpdb->get_var( $sql );

    return (int) $count;
  }


  public function getRemaining( int $limit ): array {
    $jobTable = $this->wpdb->prefix . 'icl_translate_job';
    $tmpTable = $this->wpdb->prefix . CompletedRecordsStorage::TMP_TABLE_NAME;

    $sql = $this->wpdb->prepare(
      'SELECT DISTINCT j.rid
      FROM %i j
      LEFT JOIN %i tp ON j.rid = tp.rid
      WHERE j.translated = 1
      AND (tp.rid IS NULL OR tp.processed = 0)
      LIMIT %d',
      $jobTable,
      $tmpTable,
      $limit
    );

    $results = $this->wpdb->get_results( $sql, ARRAY_A );

    return $results ?: [];
  }


}
