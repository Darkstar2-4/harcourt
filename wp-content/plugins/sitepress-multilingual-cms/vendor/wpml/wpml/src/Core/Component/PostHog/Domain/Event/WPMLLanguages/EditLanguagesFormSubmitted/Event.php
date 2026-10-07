<?php

namespace WPML\Core\Component\PostHog\Domain\Event\WPMLLanguages\EditLanguagesFormSubmitted;

use WPML\Core\Component\PostHog\Domain\Event\Event as AbstractEvent;

class Event extends AbstractEvent {


  public function getName(): string {
    return 'wpml_edit_languages_form_submitted';
  }


}
