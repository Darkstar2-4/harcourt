<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Post;

use WPML\Core\Component\WordsToTranslate\Domain\Post\Query\PostQueryInterface;
use WPML\Core\Component\WordsToTranslate\Domain\ProviderInterface;
use WPML\Core\Component\WordsToTranslate\Domain\TranslatableDTO;
use WPML\PHP\Exception\InvalidItemIdException;
use WPML\PHP\Exception\RuntimeException;

class Provider implements ProviderInterface{
  const TYPE = 'post';

  private $postContentLoader;

  private $postTermsLoader;

  private $postQuery;


  public function __construct(
    PostQueryInterface $postQuery,
    PostContentLoader $postContentLoader,
    ?PostTermsLoader $postTermsLoader = null
  ) {
    $this->postQuery = $postQuery;
    $this->postContentLoader = $postContentLoader;
    $this->postTermsLoader = $postTermsLoader;
  }


  public function getByIdAndTypeForLangs( $id, $type, $langs, $freshTranslation = false ) {
    if ( $type !== self::TYPE ) {
      return false;
    }

    $post = $this->postQuery->getById( $id );

    $this->postContentLoader->loadWordsToTranslateForLangs( $post, $langs, $freshTranslation );

    $this->postTermsLoader &&
      $this->postTermsLoader->loadWordsToTranslateForLangs( $post, $langs );

    return $post;
  }


  public function useThisContentForItem( $id, $type, $content ) {
    if ( $type !== self::TYPE ) {
      return;
    }

    $this->postContentLoader->getJobQuery()->useThisContentForItem( $id, $content );
  }


}
