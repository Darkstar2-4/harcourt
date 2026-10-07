<?php

namespace WPML\Core\SharedKernel\Component\Post\Domain\Repository;

use WPML\Core\SharedKernel\Component\Post\Domain\Post;
use WPML\PHP\Exception\InvalidItemIdException;

interface RepositoryInterface {


  public function getById( int $postId ): Post;


}
