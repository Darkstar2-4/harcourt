<?php

namespace WPML\Core\Component\Translation\Domain\Repository;

use WPML\Core\Component\Translation\Domain\Entity\JobError;

interface JobErrorRepositoryInterface {


  public function findByJobId( int $jobId );


  public function insert( JobError $jobError );


  public function incrementCounter( int $jobId );


  public function delete( int $jobId );


  public function count(): int;


}
