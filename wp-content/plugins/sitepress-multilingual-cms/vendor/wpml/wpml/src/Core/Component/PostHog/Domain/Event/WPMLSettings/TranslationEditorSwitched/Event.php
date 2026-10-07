<?php

namespace WPML\Core\Component\PostHog\Domain\Event\WPMLSettings\TranslationEditorSwitched;

use WPML\Core\Component\PostHog\Domain\Event\Event as AbstractEvent;

class Event extends AbstractEvent {


  public function getName(): string {
    return 'wpml_translation_editor_switched';
  }


}
