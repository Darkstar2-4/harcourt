<?php

namespace WPML\Core\Component\PostHog\Domain\Event\WPMLSettings\ATEForOldTranslationsEnabled;

use WPML\Core\Component\PostHog\Domain\Event\Event as AbstractEvent;

class Event extends AbstractEvent {


  public function getName(): string {
    return 'wpml_ate_for_old_translations_enabled';
  }


}
