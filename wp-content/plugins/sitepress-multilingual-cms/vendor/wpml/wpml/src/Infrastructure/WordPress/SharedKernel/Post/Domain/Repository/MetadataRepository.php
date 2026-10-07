<?php

namespace WPML\Infrastructure\WordPress\SharedKernel\Post\Domain\Repository;

use WPML\Core\SharedKernel\Component\Post\Domain\Repository\MetadataRepositoryInterface;

class MetadataRepository implements MetadataRepositoryInterface {


  public function get( int $postId, string $metaKey ) {
    return get_post_meta( $postId, $metaKey );
  }


  public function update( int $postId, string $metaKey, string $value ) {
    return update_post_meta( $postId, $metaKey, $value );
  }


}
