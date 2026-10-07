<?php

namespace WPML\Core\Component\ReportContentStats\Domain\Repository;

interface RetryRepositoryInterface {


  public function get();


  public function update( array $retryData );


  public function delete();


}
