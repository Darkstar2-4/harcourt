<?php

namespace WPML\Core\Component\PostHog\Domain\Event\WPMLLanguages\RootPageSaved;

use WPML\Core\Component\PostHog\Domain\Event\Event as AbstractEvent;

class Event extends AbstractEvent {


  public function getName(): string {
    return 'wpml_root_page_saved';
  }


}
