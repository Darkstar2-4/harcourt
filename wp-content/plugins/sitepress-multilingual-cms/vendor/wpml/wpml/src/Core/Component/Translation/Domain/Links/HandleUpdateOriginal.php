<?php

namespace WPML\Core\Component\Translation\Domain\Links;

class HandleUpdateOriginal {

  private $collector;

  private $repository;


  public function __construct(
    CollectorInterface $collector,
    RepositoryInterface $repository
  ) {
    $this->collector = $collector;
    $this->repository = $repository;
  }


  public function handle( Item $item ) {
    if (
      ! $item->isOriginal()
      || ! $item->canLinkToOtherItems()
      || ! $item->isPublishable()
    ) {
      return;
    }

    $this->collectRelationships( $item );
  }


  private function collectRelationships( Item $item ) {
    $itemContent = $item->getContent() ?? '';
    $itemExcerpt = $item->getExcerpt() ?? '';

    $linksNow = $this->collector->getItemsLinkedInContent(
      $itemContent . $itemExcerpt
    );

    $linksBefore = $this->repository->getToItemsByFromItem(
      $item
    );

    if ( ! $linksNow && ! $linksBefore ) {
      return;
    }

    if ( ! $linksNow ) {
      $this->repository->deleteAllRelationshipsFrom( $item );
      return;
    }

    $diffItems = function( Item $a, Item $b ): int {
      return $a->getId().$a->getType() <=> $b->getId().$b->getType();
    };

    $linksToDelete = array_udiff( $linksBefore, $linksNow, $diffItems );

    foreach ( $linksToDelete as $itemTo ) {
      $this->repository->deleteRelationship( $item, $itemTo );
    }

    $linksToAdd = array_udiff( $linksNow, $linksBefore, $diffItems );
    foreach ( $linksToAdd as $itemTo ) {
      if ( $itemTo->isDeleted() ) {
        continue;
      }

      $this->repository->addRelationship( $item, $itemTo );
    }
  }


}
