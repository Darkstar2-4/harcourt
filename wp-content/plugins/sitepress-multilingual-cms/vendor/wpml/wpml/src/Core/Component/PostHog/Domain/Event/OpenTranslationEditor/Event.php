<?php

namespace WPML\Core\Component\PostHog\Domain\Event\OpenTranslationEditor;

use WPML\Core\Component\PostHog\Domain\Event\Event as AbstractEvent;
use WPML\Core\Component\PostHog\Domain\Event\TEAEventInterface;

class Event extends AbstractEvent implements TEAEventInterface {


  public function getName(): string {
    return 'wpml_open_translation_editor';
  }


}
