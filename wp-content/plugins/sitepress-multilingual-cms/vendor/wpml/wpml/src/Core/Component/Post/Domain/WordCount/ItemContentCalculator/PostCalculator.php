<?php

namespace WPML\Core\Component\Post\Domain\WordCount\ItemContentCalculator;

use WPML\Core\Component\Post\Domain\WordCount\Calculator\Calculator;
use WPML\Core\SharedKernel\Component\Post\Domain\Repository\MetadataRepositoryInterface;
use WPML\Core\SharedKernel\Component\Post\Domain\Repository\RepositoryInterface;
use WPML\PHP\Exception\InvalidItemIdException;

class PostCalculator {

  const WORD_COUNT_META_KEY = '_wpml_word_count';

  private $simplePostQuery;

  private $calculator;

  private $metadata;

  private $contentFilter;


  public function __construct(
    RepositoryInterface $simplePostQuery,
    Calculator $calculator,
    MetadataRepositoryInterface $metadata,
    PostContentFilterInterface $additionalContentFilter
  ) {
    $this->simplePostQuery = $simplePostQuery;
    $this->calculator      = $calculator;
    $this->metadata        = $metadata;
    $this->contentFilter   = $additionalContentFilter;
  }


  public function calculate( int $itemId ): int {
    $post = $this->simplePostQuery->getById( $itemId );

    $content           = $this->contentFilter->getContent( $post->getContent(), $post->getId() ) ?: '';
    $additionalContent = $this->contentFilter->getAdditionalContent( '', $itemId ) ?: '';

    $wordCount = $this->calculator->words(
      $post->getTitle() . $post->getExcerpt() . $content . $additionalContent
    );

    $this->metadata->update( $itemId, self::WORD_COUNT_META_KEY, (string) $wordCount );

    return $wordCount;
  }


  public function getWordCount( int $postId ): int {
    $wordCount = $this->metadata->get( $postId, self::WORD_COUNT_META_KEY );

    return $wordCount && is_numeric( $wordCount ) ? (int) $wordCount : $this->calculate( $postId );
  }


}
