<?php

namespace WPML\Core\Component\Post\Domain\WordCount\ItemContentCalculator;

use WPML\Core\Component\Post\Domain\WordCount\Calculator\Calculator;
use WPML\Core\SharedKernel\Component\String\Domain\Repository\RepositoryInterface;
use WPML\PHP\Exception\InvalidArgumentException;
use WPML\PHP\Exception\InvalidItemIdException;
use function WPML\PHP\Logger\error;

class StringCalculator {

  private $calculator;

  private $stringRepository;


  public function __construct( Calculator $calculator, RepositoryInterface $stringRepository ) {
    $this->calculator       = $calculator;
    $this->stringRepository = $stringRepository;
  }


  public function calculate( int $itemId ): int {
    $string    = $this->stringRepository->get( $itemId );
    $wordCount = $this->calculator->words( $string->getValue() );

    try {
      $this->stringRepository->updateField( $itemId, 'word_count', $wordCount );
    } catch ( InvalidArgumentException $e ) {
      error( sprintf( 'Failed to update word count for string %d', $itemId ) );
    }

    return $wordCount;
  }


}
