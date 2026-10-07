<?php

namespace WPML\UserInterface\Web\Infrastructure\WordPress\Events\WpmlPosthog\PostHogRecording;

use WPML\Core\Component\PostHog\Application\Service\CheckPostHogShouldRecordService;
use WPML\Core\Port\Event\EventListenerInterface;

class PostHogShouldRecordListener implements EventListenerInterface {

  private $checkPostHogShouldRecordService;


  public function __construct(
    CheckPostHogShouldRecordService $checkPostHogShouldRecordService
  ) {
    $this->checkPostHogShouldRecordService = $checkPostHogShouldRecordService;
  }


  public function check() {
    $this->checkPostHogShouldRecordService->run();
  }


}
