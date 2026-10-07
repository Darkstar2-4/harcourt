<?php
namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetPopulatedItemSections;

use WPML\Core\Component\Post\Application\Query\Criteria\SearchPopulatedTypesCriteria;

interface PopulatedItemSectionsFilterInterface {


  public function filter( array $itemSectionIds, SearchPopulatedTypesCriteria $searchCriteria );


}
