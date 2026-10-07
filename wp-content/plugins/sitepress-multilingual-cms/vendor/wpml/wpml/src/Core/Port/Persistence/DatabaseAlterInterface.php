<?php

namespace WPML\Core\Port\Persistence;

use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\PHP\Exception\InvalidArgumentException;

interface DatabaseAlterInterface {
  const FIELD_TYPE_INT11_UNSIGNED = 'INT(11) UNSIGNED';


  public function addIndex( string $table, $fields, ?string $name = null );


  public function addColumn( string $table, string $column, $type, $default = null );


  public function dropColumn( string $table, string $column );


  public function truncateColumn( string $table, string $column );


}
