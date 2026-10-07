<?php

namespace WPML\Core\Component\PostHog\Domain\Event\WPMLLanguages\SetLanguageUrlFormat;

use WPML\Core\Component\PostHog\Domain\Event\Event as AbstractEvent;

class Event extends AbstractEvent {


  public function getName(): string {
    return 'wpml_set_language_url_format';
  }


}
