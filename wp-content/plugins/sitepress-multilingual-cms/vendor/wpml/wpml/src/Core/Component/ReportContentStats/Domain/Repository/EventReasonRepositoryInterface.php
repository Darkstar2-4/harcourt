<?php

namespace WPML\Core\Component\ReportContentStats\Domain\Repository;

interface EventReasonRepositoryInterface {


  public function get();


  public function set( string $reason );


  public function clear();


}
