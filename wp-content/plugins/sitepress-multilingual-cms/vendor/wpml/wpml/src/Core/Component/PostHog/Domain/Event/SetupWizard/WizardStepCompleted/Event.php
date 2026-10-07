<?php

namespace WPML\Core\Component\PostHog\Domain\Event\SetupWizard\WizardStepCompleted;

use WPML\Core\Component\PostHog\Domain\Event\Event as AbstractEvent;

class Event extends AbstractEvent {


  public function getName(): string {
    return 'wpml_setup_wizard_step_completed';
  }


}
