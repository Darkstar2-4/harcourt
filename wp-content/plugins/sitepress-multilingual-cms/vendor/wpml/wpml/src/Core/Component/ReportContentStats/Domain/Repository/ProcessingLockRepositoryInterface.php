<?php

namespace WPML\Core\Component\ReportContentStats\Domain\Repository;

use WPML\Core\Component\ReportContentStats\Domain\ProcessingLock;

interface ProcessingLockRepositoryInterface {


  public function acquire(): bool;


  public function get();


  public function release();


  public function update( ProcessingLock $lock );


}
