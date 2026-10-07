<?php

namespace WPML\Core\SharedKernel\Component\WpmlOrgClient\Domain\Api\Endpoints;

interface PostHogRecordingInterface {


  public function run(
    string $siteKey,
    string $recordingMode = 'default',
    string $wpmlVersion = '',
    string $teaState = ''
  ): array;


}
