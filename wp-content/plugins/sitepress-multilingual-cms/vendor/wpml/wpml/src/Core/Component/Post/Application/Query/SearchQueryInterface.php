<?php

namespace WPML\Core\Component\Post\Application\Query;

use WPML\Core\Component\Post\Application\Query\Criteria\SearchCriteria;
use WPML\Core\Component\Post\Application\Query\Dto\PostWithTranslationStatusDto;
use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;
use WPML\Core\Port\Persistence\ResultCollectionInterface;

interface SearchQueryInterface {


  public function get( SearchCriteria $criteria );


  public function count( SearchCriteria $criteria ): int;


}
