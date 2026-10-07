<?php

namespace WPML\Core\Port\Persistence;

use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;

interface DatabaseWriteInterface {


  public function insert( string $table, array $entityData ): int;


  public function insertMany( string $table, array $entitiesData );


  public function update( string $table, array $entityData, array $whereData ): int;


  public function delete( string $table, array $whereData ): int;


}
