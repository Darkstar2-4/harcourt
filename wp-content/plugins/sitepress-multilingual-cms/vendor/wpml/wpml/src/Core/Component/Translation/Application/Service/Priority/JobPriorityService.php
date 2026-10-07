<?php

namespace WPML\Core\Component\Translation\Application\Service\Priority;

use WPML\Core\Component\Translation\Application\Query\Priority\PostDataQueryInterface;
use WPML\Core\Component\Translation\Application\Query\Priority\StringDataQueryInterface;
use WPML\Core\Component\Translation\Domain\Priority\OrderingPayload;

class JobPriorityService {

    private $sorter;

    private $contextBuilder;

    private $itemBuilder;

    private $payloadBuilder;

    private $postDataQuery;

    private $stringDataQuery;


  public function __construct(
        JobPrioritySorter $sorter,
        ClassificationContextBuilder $contextBuilder,
        PrioritizableItemBuilder $itemBuilder,
        OrderingPayloadBuilder $payloadBuilder,
        ?PostDataQueryInterface $postDataQuery = null,
        ?StringDataQueryInterface $stringDataQuery = null
    ) {
      $this->sorter          = $sorter;
      $this->contextBuilder  = $contextBuilder;
      $this->itemBuilder     = $itemBuilder;
      $this->payloadBuilder  = $payloadBuilder;
      $this->postDataQuery   = $postDataQuery;
      $this->stringDataQuery = $stringDataQuery;
  }


  public function sortPostIds( array $postIds ): array {
    if ( empty( $postIds ) ) {
        return [];
    }

      $items      = $this->buildPostItems( $postIds );
      $parentMap  = $this->buildParentMap( $postIds );
      $context    = $this->contextBuilder->build( $parentMap );

      return $this->sorter->sortIds( $items, $context );
  }


  public function sortStringIds( array $stringIds ): array {
    if ( empty( $stringIds ) ) {
        return [];
    }

      $items   = $this->buildStringItems( $stringIds );
      $context = $this->contextBuilder->build();

      return $this->sorter->sortIds( $items, $context );
  }


  public function buildOrderingPayload(
        array $postIds,
        array $stringIds = [],
        array $packageIds = []
    ): OrderingPayload {
    $items = [];

    $items = array_merge( $items, $this->buildPostItems( $postIds ) );
    $items = array_merge( $items, $this->buildStringItems( $stringIds ) );
    $items = array_merge( $items, $this->buildPackageItems( $packageIds ) );

    $parentMap = $this->buildParentMap( $postIds );
    $context   = $this->contextBuilder->build( $parentMap );

    $sortedPriorities = $this->sorter->sort( $items, $context );

    return $this->payloadBuilder->build( $sortedPriorities, $context->getHomePageId() );
  }


  public function buildOrderingPayloadArray( array $postIds, array $stringIds = [], array $packageIds = [] ): array {
      return $this->buildOrderingPayload( $postIds, $stringIds, $packageIds )->toArray();
  }


  public function getSortedIds( array $postIds, array $stringIds = [], array $packageIds = [] ): array {
      $items = [];

      $items = array_merge( $items, $this->buildPostItems( $postIds ) );
      $items = array_merge( $items, $this->buildStringItems( $stringIds ) );
      $items = array_merge( $items, $this->buildPackageItems( $packageIds ) );

      $parentMap = $this->buildParentMap( $postIds );
      $context   = $this->contextBuilder->build( $parentMap );

      return $this->sorter->sortIds( $items, $context );
  }


  private function buildPostItems( array $postIds ): array {
    if ( empty( $postIds ) || $this->postDataQuery === null ) {
        return [];
    }

      $items    = [];
      $postData = $this->postDataQuery->getPostsData( $postIds );

    foreach ( $postData as $post ) {
        $items[] = $this->itemBuilder->buildFromPost(
          $post,
          $post['is_featured'] ?? false,
          $post['stock_status'] ?? null
        );
    }

      return $items;
  }


  private function buildStringItems( array $stringIds ): array {
    if ( empty( $stringIds ) ) {
        return [];
    }

      $items = [];

    if ( $this->stringDataQuery !== null ) {
        $stringData = $this->stringDataQuery->getStringsData( $stringIds );
      foreach ( $stringData as $string ) {
          $items[] = $this->itemBuilder->buildFromString(
            $string['id'],
            $string['domain'] ?? null,
            $string['context'] ?? null
          );
      }
    } else {
      foreach ( $stringIds as $stringId ) {
          $items[] = $this->itemBuilder->buildFromString( $stringId );
      }
    }

      return $items;
  }


  private function buildPackageItems( array $packageIds ): array {
      $items = [];

    foreach ( $packageIds as $packageId ) {
        $items[] = $this->itemBuilder->buildFromPackage( $packageId );
    }

      return $items;
  }


  private function buildParentMap( array $postIds ): array {
    if ( empty( $postIds ) || $this->postDataQuery === null ) {
        return [];
    }

      return $this->postDataQuery->getParentMap( $postIds );
  }


}
