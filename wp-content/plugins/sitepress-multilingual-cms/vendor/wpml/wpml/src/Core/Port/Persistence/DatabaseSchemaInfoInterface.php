<?php

namespace WPML\Core\Port\Persistence;

use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\PHP\Exception\InvalidArgumentException;

interface DatabaseSchemaInfoInterface {


  public function doesColumnExist( string $table, string $column ): bool;


  public function doesTableExist( string $table ): bool;


}
