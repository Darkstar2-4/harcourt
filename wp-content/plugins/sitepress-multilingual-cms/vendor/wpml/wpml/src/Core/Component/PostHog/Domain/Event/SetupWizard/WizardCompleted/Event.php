<?php

namespace WPML\Core\Component\PostHog\Domain\Event\SetupWizard\WizardCompleted;

use WPML\Core\Component\PostHog\Domain\Event\Event as AbstarctEvent;

class Event extends AbstarctEvent {


  public function getName(): string {
    return 'wpml_setup_wizard_completed';
  }


}
