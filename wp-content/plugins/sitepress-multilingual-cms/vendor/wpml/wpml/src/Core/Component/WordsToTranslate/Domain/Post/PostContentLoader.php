<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Post;

use WPML\Core\Component\WordsToTranslate\Domain\Calculator\WordsToTranslate;
use WPML\Core\Component\WordsToTranslate\Domain\LastTranslationFactory;
use WPML\Core\Component\WordsToTranslate\Domain\Post\Query\JobQueryInterface;
use WPML\Core\Component\WordsToTranslate\Domain\Post\Query\TranslationQueryInterface;

class PostContentLoader {

  private $wordsToTranslate;

  private $store;

  private $translationQuery;

  private $jobQuery;

  private $lastTranslationFactory;


  public function __construct(
    WordsToTranslate $wordsToTranslate,
    Store $store,
    TranslationQueryInterface $translationQuery,
    JobQueryInterface $jobQuery,
    LastTranslationFactory $lastTranslationFactory
  ) {
    $this->wordsToTranslate = $wordsToTranslate;
    $this->store = $store;
    $this->translationQuery = $translationQuery;
    $this->jobQuery = $jobQuery;
    $this->lastTranslationFactory = $lastTranslationFactory;
  }


  public function loadWordsToTranslateForLangs( Post $post, $langs, $freshTranslation = false ) {
    if ( ! $freshTranslation ) {
      $missingTranslations = $this->store->loadLastTranslations( $post, $langs );
      if ( empty( $missingTranslations ) ) {
        return;
      }
    }

    foreach ( $langs as $lang ) {
      $job = $this->jobQuery->getContentToTranslateForLang( $post, $lang );
      $post->setContent( $job->getContent() );

      $lastTranslation = $this->lastTranslationFactory->createForItem( $post, $lang );

      if ( $freshTranslation ) {
        $lastTranslation->setOriginalContent( '' );
      } else {
        $lastTranslationContent = $lastTranslation->getOriginalContent();
        if ( $lastTranslationContent === null ) {
          $lastTranslationContent =
          $this->translationQuery->getLastTranslatedOriginalContentForPost(
            $post,
            $lang,
            $job->getTranslatableFields()
          );
          $lastTranslation->setOriginalContent( $lastTranslationContent );
        }
      }

      $this->wordsToTranslate->forLastTranslation( $lastTranslation, $post );
      $post->addLastTranslation( $lastTranslation );
    }
  }


  public function getJobQuery() {
    return $this->jobQuery;
  }


}
