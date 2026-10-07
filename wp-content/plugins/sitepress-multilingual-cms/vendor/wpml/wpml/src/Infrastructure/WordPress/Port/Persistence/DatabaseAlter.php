<?php

namespace WPML\Infrastructure\WordPress\Port\Persistence;

use WPML\Core\Port\Persistence\DatabaseAlterInterface;
use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\Core\Port\Persistence\QueryPrepareInterface;
use WPML\PHP\Exception\InvalidArgumentException;


class DatabaseAlter implements DatabaseAlterInterface {

  private $wpdb;

  private $queryPrepare;


  public function __construct( $wpdb, QueryPrepareInterface $queryPrepare ) {
    $this->wpdb         = $wpdb;
    $this->queryPrepare = $queryPrepare;
  }


  public function addIndex( string $table, $fields, ?string $name = null ) {
    if ( empty( $fields ) ) {
      throw new InvalidArgumentException( 'No fields provided for index creation.' );
    }

    $fields = ! is_array( $fields ) ? [ $fields ] : $fields;
    foreach ( $fields as &$field ) {
      if ( empty( $field ) || ! is_string( $field ) ) {
        throw new InvalidArgumentException( 'Field names must be a non-empty string.' );
      }
      $field = $this->queryPrepare->escString( $field );
    }

    $name = $name ? $this->queryPrepare->escString( $name ) : $fields[0];

    $table = $this->wpdb->prefix . $this->queryPrepare->escString( $table );

    $indexExists = $this->wpdb->get_results(
      "SHOW INDEX FROM `$table` WHERE Key_name = '$name'"
    );

    if ( $indexExists ) {
      return true;
    }

    $this->wpdb->query(
      "ALTER TABLE `$table` ADD INDEX `$name` ( `" . implode( '`, `', $fields ) . "` )"
    );

    if ( $this->wpdb->last_error ) {
      throw new DatabaseErrorException( $this->wpdb->last_error );
    }

    return true;
  }


  public function addColumn( string $table, string $column, $type, $default = null ) {
    $column = $this->queryPrepare->escString( $column );

    $table = $this->wpdb->prefix . $this->queryPrepare->escString( $table );

    $fieldExists = $this->wpdb->get_results(
      "SHOW COLUMNS FROM `$table` LIKE '$column'"
    );

    if ( $fieldExists ) {
      return true;
    }

    $default = $default !== null
      ? "DEFAULT $default"
      : 'NULL';

    $this->wpdb->query(
      "ALTER TABLE `$table` ADD $column $type $default"
    );

    if ( $this->wpdb->last_error ) {
      throw new DatabaseErrorException( $this->wpdb->last_error );
    }

    return true;
  }


  public function dropColumn( string $table, string $column ) {
    if ( empty( $table ) || empty( $column ) ) {
      throw new InvalidArgumentException( 'Table and column names must be non-empty strings.' );
    }

    $table  = $this->wpdb->prefix . $this->queryPrepare->escString( $table );
    $column = $this->queryPrepare->escString( $column );

    $columnExists = $this->wpdb->get_results(
      "SHOW COLUMNS FROM `$table` LIKE '$column'"
    );

    if ( ! $columnExists ) {
      return true;
    }

    $this->wpdb->query(
      "ALTER TABLE `$table` DROP COLUMN `$column`"
    );

    if ( $this->wpdb->last_error ) {
      throw new DatabaseErrorException( $this->wpdb->last_error );
    }

    return true;
  }


  public function truncateColumn( string $table, string $column ) {
    if ( empty( $table ) || empty( $column ) ) {
      throw new InvalidArgumentException( 'Table and column names must be non-empty strings.' );
    }

    $table  = $this->wpdb->prefix . $this->queryPrepare->escString( $table );
    $column = $this->queryPrepare->escString( $column );

    $this->wpdb->query(
      "UPDATE `$table` SET `$column` = NULL"
    );

    if ( $this->wpdb->last_error ) {
      throw new DatabaseErrorException( $this->wpdb->last_error );
    }

    return true;
  }


}
