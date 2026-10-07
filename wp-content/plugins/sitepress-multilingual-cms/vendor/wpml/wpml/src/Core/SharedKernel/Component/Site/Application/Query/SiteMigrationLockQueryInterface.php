<?php

namespace WPML\Core\SharedKernel\Component\Site\Application\Query;

interface SiteMigrationLockQueryInterface {


  public function isLocked(): bool;


}
