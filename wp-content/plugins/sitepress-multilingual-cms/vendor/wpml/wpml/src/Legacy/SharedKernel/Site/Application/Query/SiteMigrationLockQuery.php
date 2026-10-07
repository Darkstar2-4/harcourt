<?php

namespace WPML\Legacy\SharedKernel\Site\Application\Query;

use WPML\Core\SharedKernel\Component\Site\Application\Query\SiteMigrationLockQueryInterface;
use WPML\TM\ATE\ClonedSites\Lock;

class SiteMigrationLockQuery implements SiteMigrationLockQueryInterface {


  public function isLocked(): bool {
    return Lock::isLocked();
  }


}
