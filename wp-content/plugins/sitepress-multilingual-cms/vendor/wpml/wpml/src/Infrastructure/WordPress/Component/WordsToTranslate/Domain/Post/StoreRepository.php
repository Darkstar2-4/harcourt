<?php

namespace WPML\Infrastructure\WordPress\Component\WordsToTranslate\Domain\Post;

use WPML\Core\Component\WordsToTranslate\Domain\Post\Post;
use WPML\Core\Component\WordsToTranslate\Domain\Post\StoreRepositoryInterface;

class StoreRepository implements StoreRepositoryInterface {


  public function save( Post $post ) {
  }


  public function get( $idPost ) {
    return null;
  }


}
