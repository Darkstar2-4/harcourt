<?php

namespace WPML\Core\Port\Persistence;

use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;

interface QueryHandlerInterface {


  public function query( string $query );


  public function queryOne( string $query );


  public function querySingle( string $query );


  public function queryColumn( string $query );


}
