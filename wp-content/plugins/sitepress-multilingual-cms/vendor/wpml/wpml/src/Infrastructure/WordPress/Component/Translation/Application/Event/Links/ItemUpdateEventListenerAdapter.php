<?php

namespace WPML\Infrastructure\WordPress\Component\Translation\Application\Event\Links;

use WPML\Core\Component\Translation\Application\Event\Links\ItemUpdateListener;
use WPML\Core\Component\Translation\Domain\Links\Item;
use WPML\Infrastructure\WordPress\Component\Translation\Domain\Links\Repository;
use WPML\Infrastructure\WordPress\SharedKernel\Post\Domain\PublicationStatusDefinitions;
use WPML\PHP\Exception\InvalidTypeException;
use WPML\WordPress\Term;
use WP_Post;

class ItemUpdateEventListenerAdapter {

  private $itemUpdateListener;

  private $publicationStatusDefinitions;

  private $repository;

  private $updatedPosts = [];

  private $updatedTerms = [];


  public function __construct(
    ItemUpdateListener $itemUpdateListener,
    PublicationStatusDefinitions $publicationStatusDefinitions,
    Repository $repository
  ) {
    $this->itemUpdateListener = $itemUpdateListener;
    $this->publicationStatusDefinitions = $publicationStatusDefinitions;
    $this->repository = $repository;
  }


  public function onPostUpdate( WP_Post $postBeforeSave ) {
    $postId = $postBeforeSave->ID;
    if ( array_key_exists( $postId, $this->updatedPosts ) ) {
      return;
    }
    $this->setDefaultsForPost( $postId );
    $this->updatedPosts[ $postId ]['statusBefore'] = $postBeforeSave->post_status;
    $this->updatedPosts[ $postId ]['nameBefore'] = $postBeforeSave->post_name;
  }


  public function onPostSave( int $postId, WP_Post $wpPost ) {
    $this->setDefaultsForPost( $postId );
    $this->updatedPosts[ $postId ]['post'] = $wpPost;
  }


  private function setDefaultsForPost( int $postId ) {
    if ( array_key_exists( $postId, $this->updatedPosts ) ) {
      return;
    }
    $this->updatedPosts[ $postId ] = [
      'post' => null,
      'statusBefore' => '',
      'nameBefore' => '',
    ];
  }


  public function onTermCreation( int $termId ) {
    if ( array_key_exists( $termId, $this->updatedTerms ) ) {
      return;
    }

    $this->updatedTerms[ $termId ] = '';
  }


  public function beforeTermUpdate( int $termId ) {
    if ( array_key_exists( $termId, $this->updatedTerms ) ) {
      return;
    }

    $term = Term::get( $termId, '', 'ARRAY_A' );
    if ( is_array( $term ) ) {
      $this->updatedTerms[ $termId ] = (string) $term['slug'];
    }
  }


  public function onShutdown() {
    if ( ! $this->updatedPosts && ! $this->updatedTerms ) {
      return;
    }

    foreach ( $this->updatedPosts as $post ) {
      try {
        if ( $post['post'] === null ) {
          continue;
        }

        $item = $this->getItemByPost(
          $post['post'],
          $post['statusBefore'],
          $post['nameBefore']
        );

        $this->itemUpdateListener->onItemSave( $item );
      } catch ( InvalidTypeException $e ) {
        continue;
      }
    }

    foreach ( $this->updatedTerms as $termId => $slugBefore ) {
      try {
        $item = $this->getItemByTerm( $termId, $slugBefore );

        if ( $item === null ) {
          continue;
        }
        $this->itemUpdateListener->onItemSave( $item );
      } catch ( InvalidTypeException $e ) {
        continue;
      }
    }
  }


  private function getItemByPost(
    WP_Post $wpPost,
    string $statusBefore,
    string $nameBefore
  ): Item {
      $item = $this->repository->get( $wpPost->ID, Repository::TYPE_POST );
    if (
        $this->publicationStatusDefinitions->gotPublished(
          $wpPost->post_status,
          $statusBefore
        )
      ) {
      $item->markAsGotPublished();
    }

    if ( $wpPost->post_name !== $nameBefore ) {
      $item->markLinkAsChanged( $nameBefore );
    }

      return $item;
  }


  private function getItemByTerm( int $termId, string $slugBefore ) {
    $term = Term::get( $termId, '', 'ARRAY_A' );

    if ( ! is_array( $term ) ) {
        return null;
    }

    $item = $this->repository->get( $termId, Repository::TYPE_TERM );
    $item->markAsPublished();

    if ( $term['slug'] !== $slugBefore ) {
      $item->markLinkAsChanged( $slugBefore );
    }

    return $item;
  }


}
