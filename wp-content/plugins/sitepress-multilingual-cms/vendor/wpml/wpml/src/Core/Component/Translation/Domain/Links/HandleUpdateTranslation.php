<?php

namespace WPML\Core\Component\Translation\Domain\Links;

class HandleUpdateTranslation {

  private $adjustLinks;

  private $repository;

  private $adjustedItems = [];


  public function __construct(
    AdjustLinksInterface $adjustLinks,
    RepositoryInterface $repository
  ) {
    $this->adjustLinks = $adjustLinks;
    $this->repository = $repository;
  }


  public function handle( Item $item ) {
    if (
      $item->isOriginal()
      || ! $item->isPublishable()
    ) {
      return;
    }

    if ( $item->canLinkToOtherItems() ) {
      $this->adjustOnlyOnce( $item );
    }

    $this->triggerOtherPostsLinksAdjustment( $item );

    $this->flushCache( $item );
  }


  private function triggerOtherPostsLinksAdjustment( Item $itemTo ) {
    if (
      ! $itemTo->isPublished()
      || ( ! $itemTo->gotPublished() && ! $itemTo->linkHasChanged() )
    ) {
      return;
    }

    $itemsFrom = $this->repository->getFromItemsByToItem( $itemTo );

    foreach ( $itemsFrom as $itemFrom ) {
      if ( $itemFrom->isDeleted() ) {
        $this->repository->deleteRelationship( $itemFrom, $itemTo );
        return;
      }

      $this->adjustOnlyOnce( $itemFrom, $itemTo );
    }
  }


  private function adjustOnlyOnce( Item $item, ?Item $triggerItem = null ) {
    $itemIdAndType = $item->getId() . $item->getType();
    if ( array_key_exists( $itemIdAndType, $this->adjustedItems ) ) {
      return;
    }

    $this->adjustLinks->adjust( $item, $triggerItem );
    $this->adjustedItems[ $itemIdAndType ] = true;
  }


  private function flushCache( Item $item ) {
    if ( $item->getType() !== 'post' ) {
      return;
    }

    $itemId   = $item->getId();
    $postType = get_post_field( 'post_type', $itemId );

    if ( ! $postType ) {
      return;
    }

    clean_object_term_cache( $itemId, $postType );
  }


}
