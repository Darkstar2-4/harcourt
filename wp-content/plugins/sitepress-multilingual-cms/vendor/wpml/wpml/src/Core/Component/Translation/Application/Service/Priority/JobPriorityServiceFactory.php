<?php

namespace WPML\Core\Component\Translation\Application\Service\Priority;

use WPML\Core\Component\Translation\Application\Query\Priority\PostDataQueryInterface;
use WPML\Core\Component\Translation\Application\Query\Priority\StringDataQueryInterface;

class JobPriorityServiceFactory {


  public static function create(
        $postDataQuery = null,
        $stringDataQuery = null,
        array $stringDomainPriorities = [],
        array $stringContextPriorities = [],
        array $cptPriorities = []
    ): JobPriorityService {
      $sorter         = new JobPrioritySorter();
      $contextBuilder = new ClassificationContextBuilder(
        null,
        $stringDomainPriorities,
        $stringContextPriorities,
        $cptPriorities
      );
      $itemBuilder    = new PrioritizableItemBuilder();
      $payloadBuilder = new OrderingPayloadBuilder();

      return new JobPriorityService(
        $sorter,
        $contextBuilder,
        $itemBuilder,
        $payloadBuilder,
        $postDataQuery,
        $stringDataQuery
      );
  }


  public static function createDefault(): JobPriorityService {
      return self::create();
  }


}
