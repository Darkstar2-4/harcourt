<?php

namespace WPML\Core\Component\Translation\Application\Service\Event;

class CancelAllAutomaticJobsEvent extends \WPML\Core\Port\Event\Event {


  public function __construct() {
    parent::__construct( 'wpml_cancel_all_automatic_jobs' );
  }


}
