<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Post;

use WPML\Core\Component\WordsToTranslate\Domain\Calculator\WordsToTranslate;
use WPML\Core\Component\WordsToTranslate\Domain\LastTranslationFactory;
use WPML\Core\Component\WordsToTranslate\Domain\Post\Query\JobQueryInterface;
use WPML\Core\Component\WordsToTranslate\Domain\Post\Query\TranslationQueryInterface;

class PostTermsLoader {

  private $wordsToTranslate;

  private $translationQuery;

  private $jobQuery;

  private $lastTranslationFactory;


  public function __construct(
    WordsToTranslate $wordsToTranslate,
    TranslationQueryInterface $translationQuery,
    JobQueryInterface $jobQuery,
    LastTranslationFactory $lastTranslationFactory
  ) {
    $this->wordsToTranslate = $wordsToTranslate;
    $this->translationQuery = $translationQuery;
    $this->jobQuery = $jobQuery;
    $this->lastTranslationFactory = $lastTranslationFactory;
  }


  public function loadWordsToTranslateForLangs( Post $post, $langs ) {
    $terms = $post->getTerms();
    if ( $terms === null ) {
      $terms = $this->jobQuery->getTerms( $post );
      $post->setTerms( $terms );
    }

    foreach ( $terms as $term ) {
      foreach ( $term->getContents() as $termContent ) {
        foreach ( $langs as $lang ) {
          $lastTranslation = $this->lastTranslationFactory->createForItem( $termContent, $lang );

          if ( $this->translationQuery->isTermTranslatable( $term, $lang ) ) {
            $this->wordsToTranslate->forLastTranslation( $lastTranslation, $termContent );
          }

          $termContent->addLastTranslation( $lastTranslation );
        }
      }
    }
  }


}
