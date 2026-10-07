<?php

namespace WPML\Core\Component\Post\Application\WordCount;

use WPML\Core\Component\Post\Domain\WordCount\ItemContentCalculator\PackageCalculator;
use WPML\Core\Component\Post\Domain\WordCount\ItemContentCalculator\PostCalculator;
use WPML\Core\Component\Post\Domain\WordCount\ItemContentCalculator\StringCalculator;
use WPML\PHP\Exception\InvalidItemIdException;

class ItemWordCountService {

  private $postCalculator;

  private $packageCalculator;

  private $stringCalculator;


  public function __construct(
    PostCalculator $postCalculator,
    PackageCalculator $packageCalculator,
    StringCalculator $stringCalculator
  ) {
    $this->postCalculator    = $postCalculator;
    $this->packageCalculator = $packageCalculator;
    $this->stringCalculator  = $stringCalculator;
  }


  public function calculatePost( int $postId, bool $forceRecalculate = false ): int {
    return $forceRecalculate ?
      $this->postCalculator->calculate( $postId ) :
      $this->postCalculator->getWordCount( $postId );
  }


  public function calculatePackage( int $packageId ): int {
    return $this->packageCalculator->calculate( $packageId );
  }


  public function calculateString( int $stringId ): int {
    return $this->stringCalculator->calculate( $stringId );
  }


}
