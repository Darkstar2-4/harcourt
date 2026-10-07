<?php

namespace WPML\Core\Component\Translation\Application\Service\Priority;

use WPML\Core\Component\Translation\Domain\Priority\JobPriority;
use WPML\Core\Component\Translation\Domain\Priority\OrderingPayload;

class OrderingPayloadBuilder {


  public function build( array $sortedPriorities, $homePostId = null ): OrderingPayload {
      $positions = [];
      $meta      = [];

    foreach ( $sortedPriorities as $priority ) {
        $jobId              = $priority->getJobId();
        $positions[ $jobId ] = $priority->getPosition();
        $meta[ $jobId ]      = $priority->toArray();
    }

      return new OrderingPayload(
        OrderingPayload::MODE_WPML_PRIORITY_V1,
        $homePostId,
        $positions,
        $meta
      );
  }


  public function buildArray( array $sortedPriorities, $homePostId = null ): array {
      return $this->build( $sortedPriorities, $homePostId )->toArray();
  }


}
