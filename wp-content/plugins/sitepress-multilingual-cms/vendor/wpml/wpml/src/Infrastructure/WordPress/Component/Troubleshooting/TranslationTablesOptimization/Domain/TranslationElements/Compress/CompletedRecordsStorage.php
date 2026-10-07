<?php

namespace WPML\Infrastructure\WordPress\Component\Troubleshooting\TranslationTablesOptimization\Domain\TranslationElements\Compress;

use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationDataService\CompletedRecordsStorageInterface;
use WPML\Core\Port\Persistence\DatabaseSchemaInfoInterface;
use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\PHP\Exception\InvalidArgumentException;

class CompletedRecordsStorage implements CompletedRecordsStorageInterface {

  private $wpdb;

  const TMP_TABLE_NAME = 'icl_translate_compressed';

  private $databaseSchemaInfo;


  public function __construct( $wpdb, DatabaseSchemaInfoInterface $databaseSchemaInfo ) {
    $this->wpdb               = $wpdb;
    $this->databaseSchemaInfo = $databaseSchemaInfo;
  }


  public function create() {
    try {
      if ( ! $this->databaseSchemaInfo->doesTableExist( self::TMP_TABLE_NAME ) ) {
        $table = $this->wpdb->prefix . self::TMP_TABLE_NAME;

        $this->wpdb->query(
          "CREATE TABLE {$table} (
            tid BIGINT UNSIGNED NOT NULL,
            compressed TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (tid)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
      }
    } catch ( DatabaseErrorException $e ) {
      return;
    } catch ( InvalidArgumentException $e ) {
      return;
    }
  }


  public function delete() {
    $this->wpdb->query( 'DROP TABLE IF EXISTS ' . $this->wpdb->prefix . self::TMP_TABLE_NAME );
  }


  public function markAsCompleted( array $recordIds ) {
    if ( empty( $recordIds ) ) {
      return;
    }

    $values = [];
    foreach ( $recordIds as $tid ) {
      $values[] = $this->wpdb->prepare( '(%d, 1)', $tid );
    }

    $table = $this->wpdb->prefix . self::TMP_TABLE_NAME;
    $sql = "INSERT INTO {$table} (tid, compressed)
      VALUES " . implode( ', ', $values ) . "
      ON DUPLICATE KEY UPDATE compressed = 1";

    $this->wpdb->query( $sql );
  }


}
