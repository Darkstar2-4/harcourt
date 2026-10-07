<?php

namespace WPML\Core\Component\Post\Application\Query;

use WPML\Core\Component\Post\Application\Query\Criteria\SearchPopulatedTypesCriteria;
use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;

interface SearchPopulatedTypesQueryInterface {


  public function get( SearchPopulatedTypesCriteria $criteria ): array;


}
