<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Post\Query;

use WPML\Core\Component\WordsToTranslate\Domain\Post\Post;
use WPML\PHP\Exception\InvalidItemIdException;

interface PostQueryInterface {


  public function getById( $id );


}
