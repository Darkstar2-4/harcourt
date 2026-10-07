<?php

namespace WPML\Core\Component\Translation\Application\Query;

use WPML\Core\Port\Persistence\Exception\DatabaseErrorException;

interface JobQueryInterface {


  public function hasAnyAutomatic(): bool;


  public function countAutomaticInProgress(): int;


}
