<?php

namespace WPML\Core\Component\PostHog\Domain\Event\WPMLSettings\PostTypeUnlocked;

use WPML\Core\Component\PostHog\Domain\Event\Event as AbstractEvent;

class Event extends AbstractEvent {


  public function getName(): string {
    return 'wpml_post_type_unlocked';
  }


}
