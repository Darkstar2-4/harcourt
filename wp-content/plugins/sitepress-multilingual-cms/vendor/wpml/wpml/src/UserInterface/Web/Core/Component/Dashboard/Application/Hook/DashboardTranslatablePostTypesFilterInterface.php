<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Hook;

use WPML\Core\SharedKernel\Component\Post\Application\Hook\PostTypeFilterInterface;

interface DashboardTranslatablePostTypesFilterInterface extends PostTypeFilterInterface {


  public function filter( array $postTypes );


}
