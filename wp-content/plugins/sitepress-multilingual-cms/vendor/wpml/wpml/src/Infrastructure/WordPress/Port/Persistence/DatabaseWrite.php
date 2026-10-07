<?php

namespace WPML\Infrastructure\WordPress\Port\Persistence;

use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;


class DatabaseWrite implements \WPML\Core\Port\Persistence\DatabaseWriteInterface {

  private $wpdb;


  public function __construct( $wpdb ) {
    $this->wpdb = $wpdb;
  }


  public function insert( string $table, array $entityData ): int {
    $table = $this->wpdb->prefix . $table;

    $this->wpdb->insert( $table, $entityData );

    if ( $this->wpdb->last_error ) {
      throw new DatabaseErrorException( $this->wpdb->last_error );
    }

    return $this->wpdb->insert_id;
  }


  public function insertMany( string $table, array $entitiesData ) {
    $table = $this->wpdb->prefix . $table;

    $fields = implode( ', ', array_keys( $entitiesData[0] ) );
    $values = $this->prepareValues( $entitiesData );

    $sql = sprintf(
      "INSERT IGNORE INTO $table ( %s ) VALUES %s",
      $fields,
      $values
    );

    $this->wpdb->query( $sql );

    if ( $this->wpdb->last_error ) {
      throw new DatabaseErrorException( $this->wpdb->last_error );
    }
  }


  private function prepareValues( array $entitiesData ): string {
    $values = array_map(
      function ( $entityData ) {
        return '(' . implode(
          ', ',
          array_map(
            function ( $value ) {
              return $this->wpdb->_real_escape( $value );
            },
            $entityData
          )
        ) . ')';
      },
      $entitiesData
    );

    return implode( ', ', $values );
  }


  public function update( string $table, array $entityData, array $whereData ): int {
    $this->wpdb->update( $this->wpdb->prefix . $table, $entityData, $whereData );

    if ( $this->wpdb->last_error ) {
      throw new DatabaseErrorException( $this->wpdb->last_error );
    }

    return $this->wpdb->rows_affected;
  }


  public function delete( string $table, array $whereData ): int {
    $this->wpdb->delete( $this->wpdb->prefix . $table, $whereData );

    if ( $this->wpdb->last_error ) {
      throw new DatabaseErrorException( $this->wpdb->last_error );
    }

    return $this->wpdb->rows_affected;
  }


}
