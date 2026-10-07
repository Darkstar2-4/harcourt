<?php

namespace WPML\UserInterface\Web\Infrastructure\WordPress\Events\Translation\StartUsingDashboardBanner;

use WPML\UserInterface\Web\Core\Component\Notices\PromoteUsingDashboard\Application\Service\TranslationsFromDashboardService;

class TranslationCreatedInDashboardListener {

  private $translationsFromDashboardService;


  public function __construct( TranslationsFromDashboardService $translationsFromDashboardService ) {
    $this->translationsFromDashboardService = $translationsFromDashboardService;

  }


  public function recordTranslation() {
    $this->translationsFromDashboardService->recordTranslator();
  }


}
