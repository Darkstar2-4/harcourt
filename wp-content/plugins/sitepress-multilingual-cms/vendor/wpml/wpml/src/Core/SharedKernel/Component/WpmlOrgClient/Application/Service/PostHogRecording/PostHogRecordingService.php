<?php

namespace WPML\Core\SharedKernel\Component\WpmlOrgClient\Application\Service\PostHogRecording;

use WPML\Core\SharedKernel\Component\WpmlOrgClient\Domain\Api\Endpoints\PostHogRecordingInterface;

class PostHogRecordingService {

  private $postHogRecording;


  public function __construct( PostHogRecordingInterface $postHogRecording ) {
    $this->postHogRecording = $postHogRecording;
  }


  public function run(
    string $siteKey,
    string $recordingMode = 'default',
    string $wpmlVersion = '',
    string $teaState = ''
  ): array {
    return $this->postHogRecording->run( $siteKey, $recordingMode, $wpmlVersion, $teaState );
  }


}
