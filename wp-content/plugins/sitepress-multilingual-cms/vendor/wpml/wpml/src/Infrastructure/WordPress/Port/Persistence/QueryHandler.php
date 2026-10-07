<?php

namespace WPML\Infrastructure\WordPress\Port\Persistence;

use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\Port\Persistence\ResultCollection;
use WPML\Core\Port\Persistence\ResultCollectionInterface;
use tad\FunctionMocker\ReturnValue;

class QueryHandler implements QueryHandlerInterface {

  private $wpdb;


  public function __construct( $wpdb ) {
    $this->wpdb = $wpdb;
  }


  public function query( string $query ) {
    $data = $this->wpdb->get_results( $query, ARRAY_A );

    if ( $this->wpdb->last_error ) {
      throw new DatabaseErrorException( 'Database query failed.' );
    }

    return new ResultCollection( $data );
  }


  public function queryOne( string $query ) {

    $data = $this->wpdb->get_row( $query, ARRAY_A );

    if ( $this->wpdb->last_error ) {
      throw new DatabaseErrorException( 'Database query failed.' );
    }

    return $data;
  }


  public function querySingle( string $query ) {
    $value = $this->wpdb->get_var( $query );

    if ( $this->wpdb->last_error ) {
      throw new DatabaseErrorException( 'Database query failed.' );
    }

    return $value;
  }


  public function queryColumn( string $query ) {
    $result = $this->wpdb->get_col( $query );

    if ( $this->wpdb->last_error ) {
      throw new DatabaseErrorException( 'Database query failed.' );
    }

    return $result;
  }


}
