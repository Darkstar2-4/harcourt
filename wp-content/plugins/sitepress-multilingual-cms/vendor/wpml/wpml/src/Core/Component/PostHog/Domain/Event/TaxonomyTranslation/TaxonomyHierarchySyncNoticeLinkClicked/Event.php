<?php

namespace WPML\Core\Component\PostHog\Domain\Event\TaxonomyTranslation\TaxonomyHierarchySyncNoticeLinkClicked;

use WPML\Core\Component\PostHog\Domain\Event\Event as AbstractEvent;

class Event extends AbstractEvent {


  public function getName(): string {
    return 'wpml_taxonomy_hierarchy_sync_notice_link_clicked';
  }


}
