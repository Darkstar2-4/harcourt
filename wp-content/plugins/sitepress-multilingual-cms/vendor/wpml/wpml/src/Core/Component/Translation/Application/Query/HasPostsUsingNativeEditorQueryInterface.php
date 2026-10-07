<?php

namespace WPML\Core\Component\Translation\Application\Query;

use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;

interface HasPostsUsingNativeEditorQueryInterface {


  public function get( array $postTypes, array $postTypesUsingWpEditor ) : bool;


}
