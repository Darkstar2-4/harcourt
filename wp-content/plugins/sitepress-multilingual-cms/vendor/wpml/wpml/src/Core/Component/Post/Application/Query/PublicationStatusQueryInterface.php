<?php

namespace WPML\Core\Component\Post\Application\Query;

use WPML\Core\Component\Post\Application\Query\Dto\PublicationStatusDto;

interface PublicationStatusQueryInterface {


  public function getNotInternalStatuses(): array;


}
