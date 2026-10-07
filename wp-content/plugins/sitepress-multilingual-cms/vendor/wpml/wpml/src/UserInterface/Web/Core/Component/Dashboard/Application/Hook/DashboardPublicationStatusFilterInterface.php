<?php
namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Hook;

use WPML\Core\Component\Post\Application\Query\Dto\PublicationStatusDto;

interface DashboardPublicationStatusFilterInterface {


  public function filterByDto( array $publicationStatusDtos );


}
