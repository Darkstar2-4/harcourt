<?php

namespace WPML\Infrastructure\WordPress\Component\Translation\Application\Query\Priority;

use WPML\Core\Component\Translation\Application\Query\Priority\SiteSettingsQueryInterface;

class SiteSettingsQuery implements SiteSettingsQueryInterface {


  public function getHomePageId() {
      $showOnFront = get_option( 'show_on_front' );
    if ( $showOnFront !== 'page' ) {
        return null;
    }

      $pageOnFrontOption = get_option( 'page_on_front' );
      $pageOnFront       = (int) $pageOnFrontOption;
    if ( $pageOnFront === 0 ) {
        return null;
    }

      return $pageOnFront;
  }


}
