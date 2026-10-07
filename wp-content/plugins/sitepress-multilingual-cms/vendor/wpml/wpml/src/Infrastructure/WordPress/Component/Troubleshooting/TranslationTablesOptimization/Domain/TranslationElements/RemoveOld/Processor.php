<?php

namespace WPML\Infrastructure\WordPress\Component\Troubleshooting\TranslationTablesOptimization\Domain\TranslationElements\RemoveOld;

use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationDataService\ProcessorInterface;

class Processor implements ProcessorInterface {

  private $wpdb;


  public function __construct( $wpdb ) {
    $this->wpdb = $wpdb;
  }


  public function process( array $records ): array {
    $processed = [];

    foreach ( $records as $record ) {
      $sql = "
        DELETE t
        FROM {$this->wpdb->prefix}icl_translate t
        INNER JOIN (
          SELECT job_id
          FROM {$this->wpdb->prefix}icl_translate_job
          WHERE rid = %d
            AND job_id < (
              SELECT MAX(job_id)
              FROM {$this->wpdb->prefix}icl_translate_job
              WHERE rid = %d AND translated = 1
            )
        ) to_delete ON t.job_id = to_delete.job_id
      ";

      $sql = $this->wpdb->prepare( $sql, $record['rid'], $record['rid'] );
      $this->wpdb->query( $sql );
      $processed[] = $record['rid'];
    }

    return $processed;
  }


}
