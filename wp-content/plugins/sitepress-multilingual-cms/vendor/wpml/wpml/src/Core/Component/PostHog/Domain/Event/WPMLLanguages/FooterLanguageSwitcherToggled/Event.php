<?php

namespace WPML\Core\Component\PostHog\Domain\Event\WPMLLanguages\FooterLanguageSwitcherToggled;

use WPML\Core\Component\PostHog\Domain\Event\Event as AbstractEvent;

class Event extends AbstractEvent {


  public function getName(): string {
    return 'wpml_footer_language_switcher_toggled';
  }


}
