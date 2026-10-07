<?php

namespace WPML\UserInterface\Web\Infrastructure\WordPress\CompositionRoot\Config\Event\Translation\Links;

use WPML\DicInterface;
use WPML\Infrastructure\WordPress\Component\Translation\Application\Event\Links\ItemUpdateEventListenerAdapter;
use WP_Post;

class ItemUpdateEvent {

  private $dic;

  private $itemUpdateEventListenerAdapter;


  public function __construct( DicInterface $dic ) {
    $this->dic = $dic;
    $this->register();
  }


  private function getItemsUpdateEventAdapter(): ItemUpdateEventListenerAdapter {
    if ( $this->itemUpdateEventListenerAdapter === null ) {
      $this->itemUpdateEventListenerAdapter =
        $this->dic->make( ItemUpdateEventListenerAdapter::class );
    }

    return $this->itemUpdateEventListenerAdapter;
  }


  public function register() {
    if ( defined( 'WPML_STICKY_LINKS_VERSION' ) ) {
        return;
    }

    add_action(
      'post_updated',
      function( $_, $__, $postBeforeSave ) {
        $this->getItemsUpdateEventAdapter()->onPostUpdate( $postBeforeSave );
      },
      10,
      3
    );

    add_action(
      'save_post',
      function( int $postId, WP_Post $post ) {
        $this->getItemsUpdateEventAdapter()->onPostSave( $postId, $post );
      },
      10,
      2
    );

    add_action(
      'create_term',
      function( int $termId ) {
        $this->getItemsUpdateEventAdapter()->onTermCreation( $termId );
      }
    );

    add_action(
      'edit_terms',
      function( int $termId ) {
        $this->getItemsUpdateEventAdapter()->beforeTermUpdate( $termId );
      }
    );

    add_action(
      'shutdown',
      function() {
        $this->getItemsUpdateEventAdapter()->onShutdown();
      }
    );
  }


}
