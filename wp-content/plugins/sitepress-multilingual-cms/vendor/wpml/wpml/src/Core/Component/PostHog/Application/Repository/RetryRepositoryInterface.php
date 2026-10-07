<?php

namespace WPML\Core\Component\PostHog\Application\Repository;

interface RetryRepositoryInterface {


  public function get();


  public function update( array $retryData );


  public function delete();


}
