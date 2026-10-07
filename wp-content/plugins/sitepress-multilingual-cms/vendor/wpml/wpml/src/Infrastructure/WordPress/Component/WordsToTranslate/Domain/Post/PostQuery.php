<?php
namespace WPML\Infrastructure\WordPress\Component\WordsToTranslate\Domain\Post;

use WPML\Core\Component\WordsToTranslate\Domain\Post\Post;
use WPML\Core\Component\WordsToTranslate\Domain\Post\Query\PostQueryInterface;
use WPML\Infrastructure\WordPress\Component\WordsToTranslate\Domain\SourceLangQueryTrait;
use WPML\PHP\Exception\InvalidItemIdException;

class PostQuery implements PostQueryInterface {
  use SourceLangQueryTrait;

  private $posts = [];



  public function getById( $id ) {
    if ( isset( $this->posts[ $id ] ) ) {
      return $this->posts[ $id ];
    }

    $wpPost = \get_post( $id );

    if ( ! $wpPost ) {
      throw new InvalidItemIdException( "Post with ID $id not found." );
    }

    $post = new Post(
      $wpPost->ID,
      $wpPost->post_type,
      $this->getSourceLang( $wpPost->ID, 'post_' . $wpPost->post_type ),
      strtotime( $wpPost->post_modified ) ?: 0
    );

    $this->posts[ $wpPost->ID ] = $post;
    return $post;
  }


}
