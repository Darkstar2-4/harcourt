<?php

namespace WPML\UserInterface\Web\Infrastructure\WordPress\Events\Item\WordCount;

use WPML\Core\Component\Post\Application\WordCount\ItemWordCountService;
use WPML\Core\Port\Event\EventListenerInterface;
use WPML\PHP\Exception\InvalidItemIdException;

class OnPostSavedListener implements EventListenerInterface {

  private $itemWordCountService;

  private $postIdsToProcess = [];


  public function __construct( ItemWordCountService $itemWordCountService ) {
    $this->itemWordCountService = $itemWordCountService;
  }


  public function onPostSaved( int $postId, $post ) {
    $excludeStatuses = [ 'auto-draft', 'trash', 'inherit' ];

    if ( ! in_array( $post->post_status, $excludeStatuses, true ) ) {
      $this->postIdsToProcess[] = $postId;
    }
  }


  public function process() {
    $this->postIdsToProcess = array_unique( $this->postIdsToProcess );

    foreach ( $this->postIdsToProcess as $postId ) {
      try {
        $this->itemWordCountService->calculatePost( $postId, true );
      } catch ( InvalidItemIdException $e ) {
      }
    }

    $this->postIdsToProcess = [];
  }


}
