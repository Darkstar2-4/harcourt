<?php

namespace WPML\Core\Component\Translation\Application\Service\ResendUnsolvableJobs;

class TimestampBatchNameGenerator {


  public function generate(): string {
    return 'Resend-' . time();
  }


}
