<?php

namespace WPML\Core\SharedKernel\Component\Post\Domain\Repository;

interface MetadataRepositoryInterface {


  public function get( int $postId, string $metaKey );


  public function update( int $postId, string $metaKey, string $value );


}
