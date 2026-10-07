<?php

namespace WPML\Core\Component\Post\Application\Query;

use WPML\Core\Component\Post\Application\Query\Criteria\TaxonomyCriteria;
use WPML\Core\Component\Post\Application\Query\Criteria\TaxonomyTermCriteria;
use WPML\Core\Component\Post\Application\Query\Dto\PostTaxonomyDto;
use WPML\Core\Component\Post\Application\Query\Dto\PostTermDto;

interface TaxonomyQueryInterface {


  public function getTaxonomies( TaxonomyCriteria $criteria ): array;


  public function getTerms( TaxonomyTermCriteria $criteria );


}
