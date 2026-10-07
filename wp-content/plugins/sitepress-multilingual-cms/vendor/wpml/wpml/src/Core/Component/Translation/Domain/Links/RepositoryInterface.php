<?php

namespace WPML\Core\Component\Translation\Domain\Links;

use WPML\PHP\Exception\InvalidTypeException;

interface RepositoryInterface {


  public function addDatabaseTablesIfNotExist(): bool;


  public function get( int $id, string $type ): Item;


  public function getToItemsByFromItem( Item $item );


  public function getFromItemsByToItem( Item $item );


  public function addRelationship( Item $from, Item $to );


  public function deleteRelationship( Item $from, Item $to );


  public function deleteAllRelationshipsFrom( Item $item );


  public function deleteAllRelationshipsTo( Item $item );


}
