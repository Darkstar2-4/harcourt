<?php

namespace WPML\UserInterface\Web\Core\Component\PostHog\Application;

use WPML\Core\Component\PostHog\Application\Service\CheckPostHogShouldRecordService;
use WPML\Core\Port\Endpoint\EndpointInterface;

class PostHogShouldRecordController implements EndpointInterface {

  private $checkPostHogShouldRecordService;


  public function __construct(
    CheckPostHogShouldRecordService $checkPostHogShouldRecordService
  ) {
    $this->checkPostHogShouldRecordService = $checkPostHogShouldRecordService;
  }


  public function handle( $requestData = null ): array {
    $result = $this->checkPostHogShouldRecordService->run();

    return [ 'success' => $result ];
  }


}
