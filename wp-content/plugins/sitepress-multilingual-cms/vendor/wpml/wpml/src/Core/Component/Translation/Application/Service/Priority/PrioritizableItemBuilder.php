<?php

namespace WPML\Core\Component\Translation\Application\Service\Priority;

use WPML\Core\Component\Translation\Domain\Priority\ItemType;
use WPML\Core\Component\Translation\Domain\Priority\PrioritizableItem;

class PrioritizableItemBuilder {


  public function buildFromPost( array $post, bool $isFeatured = false, $stockStatus = null ): PrioritizableItem {
      $modifiedGmtTimestamp = strtotime( $post['post_modified_gmt'] );
    if ( $modifiedGmtTimestamp === false ) {
        $modifiedGmtTimestamp = 0;
    }

      return new PrioritizableItem(
        $post['ID'],
        ItemType::post(),
        $post['post_type'],
        $post['post_parent'],
        $post['menu_order'],
        $post['post_title'],
        $modifiedGmtTimestamp,
        null,
        null,
        $isFeatured,
        $stockStatus
      );
  }


  public function buildFromString( int $stringId, $domain = null, $context = null ): PrioritizableItem {
      return new PrioritizableItem(
        $stringId,
        ItemType::string(),
        null,
        0,
        0,
        '',
        0,
        $domain,
        $context,
        false,
        null
      );
  }


  public function buildFromPackage( int $packageId, $domain = null, $context = null ): PrioritizableItem {
      return new PrioritizableItem(
        $packageId,
        ItemType::package(),
        null,
        0,
        0,
        '',
        0,
        $domain,
        $context,
        false,
        null
      );
  }


}
