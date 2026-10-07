<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Post;

interface StoreRepositoryInterface {


  public function save( Post $post );


  public function get( $idPost );


}
