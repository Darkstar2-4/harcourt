<?php

namespace WPML\Core\Component\PostHog\Domain\Event\WPMLSettings\AutomaticTranslationSettingsSaved;

use WPML\Core\Component\PostHog\Domain\Event\Event as AbstractEvent;

class Event extends AbstractEvent {


  public function getName(): string {
    return 'wpml_automatic_translation_settings_saved';
  }


}
