<?php

namespace WPML\Core\Component\Translation\Application\Event;

use WPML\Core\Port\Event\Event;

class JobsCancelledEvent extends Event {


  public function __construct( array $jobData ) {
    parent::__construct( 'wpml_tm_jobs_cancelled', [ $jobData ] );
  }


}
