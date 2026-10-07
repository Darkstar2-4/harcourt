<?php

namespace WPML\Infrastructure\WordPress\Port\Persistence;

use PDO;
use PDOException;
use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\Core\Port\Persistence\QueryHandlerInterface;
use WPML\Core\Port\Persistence\ResultCollection;
use WPML\Core\Port\Persistence\ResultCollectionInterface;
use tad\FunctionMocker\ReturnValue;

class PDOQueryHandler implements QueryHandlerInterface {

  private $pdo;


  public function __construct( PDO $pdo ) {
    $this->pdo = $pdo;
  }


  public function query( string $query ): ResultCollectionInterface {
    try {
      $stmt = $this->pdo->query( $query );
      if ( ! $stmt ) {
        throw new DatabaseErrorException( 'Query failed' );
      }
      $data = $stmt->fetchAll( PDO::FETCH_ASSOC );

      return new ResultCollection( $data ?: [] );
    } catch ( PDOException $e ) {
      throw new DatabaseErrorException( $e->getMessage() );
    }
  }


  public function queryOne( string $query ) {
    try {
      $stmt = $this->pdo->query( $query );
      if ( ! $stmt ) {
        throw new DatabaseErrorException( 'Query failed' );
      }

      $data = $stmt->fetch( PDO::FETCH_ASSOC );

      return $data ?: null;
    } catch ( PDOException $e ) {
      throw new DatabaseErrorException( $e->getMessage() );
    }
  }


  public function querySingle( string $query ) {
    try {
      $stmt = $this->pdo->query( $query );
      if ( ! $stmt ) {
        throw new DatabaseErrorException( 'Query failed' );
      }
      $value = $stmt->fetchColumn();

      return $value !== false ? $value : null;
    } catch ( PDOException $e ) {
      throw new DatabaseErrorException( $e->getMessage() );
    }
  }


  public function queryColumn( string $query ): array {
    try {
      $stmt = $this->pdo->query( $query );
      if ( ! $stmt ) {
        throw new DatabaseErrorException( 'Query failed' );
      }
      $result = $stmt->fetchAll( PDO::FETCH_COLUMN );

      return $result;
    } catch ( PDOException $e ) {
      throw new DatabaseErrorException( $e->getMessage() );
    }
  }


}
