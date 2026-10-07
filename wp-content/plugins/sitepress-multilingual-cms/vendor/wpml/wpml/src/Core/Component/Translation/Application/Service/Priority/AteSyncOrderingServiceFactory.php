<?php

namespace WPML\Core\Component\Translation\Application\Service\Priority;

class AteSyncOrderingServiceFactory {


  public static function create(): AteSyncOrderingService {
      $priorityService = JobPriorityServiceFactory::createDefault();

      return new AteSyncOrderingService( $priorityService );
  }


}
