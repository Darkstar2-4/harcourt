<?php

namespace WPML\Core\Component\Translation\Application\Service\Priority;

use WPML\Core\Component\Translation\Domain\Priority\OrderingPayload;

class AteSyncOrderingService {

    private $priorityService;


  public function __construct( JobPriorityService $priorityService ) {
      $this->priorityService = $priorityService;
  }


  public function buildSyncPayload(
        array $ateJobIds,
        array $postIds = [],
        array $stringIds = [],
        array $packageIds = [],
        string $wpmlVersion = '4.9.0'
    ): array {
      $payload = [
          'ids'          => $ateJobIds,
          'wpml_version' => $wpmlVersion,
      ];

      if ( ! empty( $postIds ) || ! empty( $stringIds ) || ! empty( $packageIds ) ) {
          $ordering = $this->priorityService->buildOrderingPayloadArray(
            $postIds,
            $stringIds,
            $packageIds
          );

        if ( ! empty( $ordering['positions'] ) ) {
          $payload['ordering'] = $ordering;
        }
      }

      return $payload;
  }


  public function sortAteJobIdsByPostPriority( array $ateJobIdToPostIdMap ): array {
    if ( empty( $ateJobIdToPostIdMap ) ) {
        return [];
    }

      $postIds       = array_values( $ateJobIdToPostIdMap );
      $sortedPostIds = $this->priorityService->sortPostIds( $postIds );

      $postIdToAteJobIds = [];
    foreach ( $ateJobIdToPostIdMap as $ateJobId => $postId ) {
      if ( ! isset( $postIdToAteJobIds[ $postId ] ) ) {
          $postIdToAteJobIds[ $postId ] = [];
      }
        $postIdToAteJobIds[ $postId ][] = $ateJobId;
    }

      $sortedAteJobIds = [];
    foreach ( $sortedPostIds as $postId ) {
      if ( isset( $postIdToAteJobIds[ $postId ] ) ) {
        foreach ( $postIdToAteJobIds[ $postId ] as $ateJobId ) {
          $sortedAteJobIds[] = $ateJobId;
        }
      }
    }

      return $sortedAteJobIds;
  }


  public function getOrderingPayloadForPosts( array $postIds ): OrderingPayload {
      return $this->priorityService->buildOrderingPayload( $postIds );
  }


  public function getOrderingPayloadArrayForPosts(
        array $postIds,
        array $stringIds = [],
        array $packageIds = []
    ): array {
      return $this->priorityService->buildOrderingPayloadArray( $postIds, $stringIds, $packageIds );
  }


  public function getTierAndRankForPost( int $postId ) {
      $payload = $this->priorityService->buildOrderingPayloadArray( [ $postId ] );
      $key = (string) $postId;

    if ( ! array_key_exists( $key, $payload['meta'] ) ) {
        return null;
    }

      return [
          'tier' => $payload['meta'][ $key ]['tier'],
          'rank' => $payload['meta'][ $key ]['rank'],
      ];
  }


}
