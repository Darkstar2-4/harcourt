<?php

namespace WPML\Core\Component\Translation\Application\Query\Priority;

interface PostDataQueryInterface {


  public function getPostsData( array $postIds ): array;


  public function getParentMap( array $postIds ): array;


}
