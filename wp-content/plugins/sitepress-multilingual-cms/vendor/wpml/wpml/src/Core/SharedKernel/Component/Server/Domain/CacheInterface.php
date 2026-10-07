<?php

namespace WPML\Core\SharedKernel\Component\Server\Domain;

interface CacheInterface {


  public function get( $key );


  public function set( $key, $value, int $expiration = 0 );


  public function delete( $key ): bool;


}
