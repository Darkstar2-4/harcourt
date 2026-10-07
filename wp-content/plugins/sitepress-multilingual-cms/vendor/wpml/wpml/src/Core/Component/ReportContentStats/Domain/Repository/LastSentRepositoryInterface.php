<?php

namespace WPML\Core\Component\ReportContentStats\Domain\Repository;

interface LastSentRepositoryInterface {


  public function get();


  public function update( int $lastSent );


}
