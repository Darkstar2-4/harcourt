<?php

namespace WPML\Core\Port\Persistence;

interface QueryPrepareInterface {


  public function prefix(): string;


  public function prepare( $sql, ...$args ): string;


  public function prepareIn( $items, $format = '%s' ): string;


  public function escLike( $text ): string;


  public function escString( $text );


}
