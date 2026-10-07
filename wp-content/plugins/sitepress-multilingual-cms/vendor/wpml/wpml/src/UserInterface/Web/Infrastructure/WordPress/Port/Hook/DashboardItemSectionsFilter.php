<?php

namespace WPML\UserInterface\Web\Infrastructure\WordPress\Port\Hook;

use WPML\UserInterface\Web\Core\Component\Dashboard\Application\Hook\DashboardItemSectionsFilterInterface;
use WPML\UserInterface\Web\Core\Component\Dashboard\Application\ViewModel\ItemSection;

class DashboardItemSectionsFilter implements DashboardItemSectionsFilterInterface {
  const NAME = 'wpml_tm_dashboard_item_sections';


  public function filter( array $itemSections ) {
    $finalItemSections = apply_filters( static::NAME, $itemSections );

    return $finalItemSections;

  }


  public function addNoteToSections( array $itemSections ): array {
    return apply_filters( 'wpml_tm_dashboard_item_sections_note', $itemSections );
  }


}
