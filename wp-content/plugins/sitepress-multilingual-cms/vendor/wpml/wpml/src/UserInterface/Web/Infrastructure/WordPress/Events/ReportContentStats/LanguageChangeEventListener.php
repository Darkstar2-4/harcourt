<?php

namespace WPML\UserInterface\Web\Infrastructure\WordPress\Events\ReportContentStats;

use WPML\Core\Component\ReportContentStats\Application\Service\EventReasonService;
use WPML\Core\Component\ReportContentStats\Application\Service\ResendTriggerService;

class LanguageChangeEventListener {

  private $resendTriggerService;


  public function __construct( ResendTriggerService $resendTriggerService ) {
    $this->resendTriggerService = $resendTriggerService;
  }


  public function doActions() {
    $this->resendTriggerService->trigger( EventReasonService::REASON_LANGUAGE_CHANGE );
  }


}
