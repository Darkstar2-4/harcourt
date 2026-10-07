<?php

namespace WPML\UserInterface\Web\Infrastructure\WordPress\Events\SiteLock;

use WPML\Core\Component\ReportContentStats\Application\Service\ContentStatsService;

class SiteLockDetectedEventListener {

  private $contentStatsService;


  public function __construct( ContentStatsService $contentStatsService ) {
    $this->contentStatsService = $contentStatsService;
  }


  public function doActions() {
    $this->resetContentStatsData();
  }


  private function resetContentStatsData() {
    $this->contentStatsService->resetPostTypesStatsData();
  }


}
