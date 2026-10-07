<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Post;

class Store {

  private $repository;


  public function __construct(
    StoreRepositoryInterface $repository
  ) {
    $this->repository = $repository;
  }


  public function save( Post $post ) {
    $this->repository->save( $post );
  }


  public function loadLastTranslations( Post $post, $langs ) {
    $missingLangs = [];
    $stored = $this->repository->get( $post->getId() );

    if ( ! $stored ) {
      return $langs;
    }

    if ( $stored->getLastEdit() !== $post->getLastEdit() ) {
      return $langs;
    }

    $lastTranslations = $stored->getLastTranslations();
    $missingLangs = [];

    foreach ( $langs as $lang ) {
      if ( ! isset( $lastTranslations[ $lang ] ) ) {
        $missingLangs[] = $lang;
        continue;
      }

      $post->addLastTranslation( $lastTranslations[ $lang ] );
    }

    return $missingLangs;
  }


}
