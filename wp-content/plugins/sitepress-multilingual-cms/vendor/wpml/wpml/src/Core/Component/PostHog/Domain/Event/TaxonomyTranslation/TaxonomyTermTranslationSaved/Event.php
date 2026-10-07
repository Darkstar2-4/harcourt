<?php

namespace WPML\Core\Component\PostHog\Domain\Event\TaxonomyTranslation\TaxonomyTermTranslationSaved;

use WPML\Core\Component\PostHog\Domain\Event\Event as AbstractEvent;

class Event extends AbstractEvent {


  public function getName(): string {
    return 'wpml_taxonomy_term_translation_saved';
  }


}
