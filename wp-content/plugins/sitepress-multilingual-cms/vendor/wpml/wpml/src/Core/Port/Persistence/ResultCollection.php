<?php

namespace WPML\Core\Port\Persistence;

use WPML\Core\Port\Persistence\Exception\CanOnlyHydrateFromArrayException;
use WPML\Core\Port\Persistence\Exception\EmptyQueryResultException;
use WPML\Core\Port\Persistence\Exception\NotConstructableFromArrayException;
use WPML\Core\Port\Persistence\Exception\NotUniqueQueryResultException;
use WPML\PHP\ConstructableFromArrayInterface;

class ResultCollection implements ResultCollectionInterface {

  private $itemList;


  public function __construct( array $itemList = [] ) {
    $this->itemList = $itemList;
  }


  public function getSingleResult() {
    if ( ! $this->itemList ) {
      throw new EmptyQueryResultException();
    }

    $count = $this->count();

    if ( $count > 1 ) {
      throw new NotUniqueQueryResultException();
    }

    return \reset( $this->itemList );
  }


  public function getResults() {
    return $this->itemList;
  }


  public function count(): int {
    return \count( $this->itemList );
  }


  public function hydrateResultItemsAs(
    string $classname
  ): ResultCollectionInterface {
    if (
      ! is_subclass_of( $classname, ConstructableFromArrayInterface::class )
    ) {
      throw new NotConstructableFromArrayException( $classname );
    }

    $hydratedItemList = [];
    foreach ( $this->itemList as $itemKey => $item ) {
      if ( ! \is_array( $item ) ) {
        throw new CanOnlyHydrateFromArrayException( $item );
      }

      $itemValue = $classname::fromArray( $item );

      if ( $itemValue instanceof $classname ) {
        $hydratedItemList[ $itemKey ] = $itemValue;
      }
    }

    return new self( $hydratedItemList );
  }


  public function hydrateSingleResultAs( string $classname ) {
    if (
      ! is_subclass_of( $classname, ConstructableFromArrayInterface::class )
    ) {
      throw new NotConstructableFromArrayException( $classname );
    }

    $item = $this->getSingleResult();

    if ( ! \is_array( $item ) ) {
      throw new CanOnlyHydrateFromArrayException( $item );
    }

    return $classname::fromArray( $item );
  }


}
