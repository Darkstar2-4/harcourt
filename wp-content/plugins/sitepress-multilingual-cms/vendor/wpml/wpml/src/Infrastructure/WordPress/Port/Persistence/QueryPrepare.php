<?php

namespace WPML\Infrastructure\WordPress\Port\Persistence;

use WPML\Core\Port\Persistence\QueryPrepareInterface;

class QueryPrepare implements QueryPrepareInterface {

  private $wpdb;


  public function __construct( $wpdb ) {
    $this->wpdb = $wpdb;
  }


  public function prefix(): string {
    return $this->wpdb->prefix;
  }


  public function prepare( $sql, ...$args ): string {
    $prepared = $this->wpdb->prepare( $sql, $args );
    return is_string( $prepared ) ? $prepared : '';
  }


  public function prepareIn( $items, $format = '%s' ): string {
    if ( ! is_array( $items ) ) {
      $items = [ $items ];
    }
    $prepared_in = '';
    $itemsCount  = count( $items );

    if ( $itemsCount > 0 ) {
      $placeholders    = array_fill( 0, $itemsCount, $format );
      $prepared_format = implode( ',', $placeholders );
      $prepared_in     = $this->prepare( $prepared_format, ...$items );
    }

    return $prepared_in;
  }


  public function escLike( $text ): string {
    if ( ! is_null( $text ) && trim( $text ) !== '' ) {
      return $this->wpdb->esc_like( $text );
    }

    return '';
  }


  public function escString( $text ) {
    return esc_sql( $text );
  }


}
