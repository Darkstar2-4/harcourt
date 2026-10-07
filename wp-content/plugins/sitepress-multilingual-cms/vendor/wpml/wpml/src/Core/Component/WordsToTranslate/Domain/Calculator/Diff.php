<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Calculator;

class Diff {
  const DIFF_KEY_ADDED = '+';
  const DIFF_KEY_REMOVED = '-';


  public function diffStrings( string $before, string $current ) {
    return $this->diffArrays(
      preg_split( "/[\s]+/", $before ) ?: [],
      preg_split( "/[\s]+/", $current ) ?: []
    );
  }


  public function diffArrays(
    $b,
    $c,
    $bStart = 0,
    $bEnd = null,
    $cStart = 0,
    $cEnd = null,
    $cValuesAsIndex = null
  ) {
    $bEnd = $bEnd ?? count( $b );
    $cEnd = $cEnd ?? count( $c );

    if ( $bStart >= $bEnd && $cStart >= $cEnd ) {
      return [];
    }

    if ( $cValuesAsIndex === null ) {
      $cValuesAsIndex = [];
      foreach ( $c as $index => $value ) {
        $cValuesAsIndex[ $value ][] = $index;
      }
    }

    $maxMatchingWordsInARow = 0;
    $maxMatchAStart = $bStart;
    $maxMatchBstart = $cStart;

    $matches = [];

    for ( $bIndex = $bStart; $bIndex < $bEnd; $bIndex++ ) {
      $value = $b[ $bIndex ];

      $matchIndexes = $cValuesAsIndex[ $value ] ?? [];

      foreach ( $matchIndexes as $matchIndex ) {

        if ( $matchIndex < $cStart || $matchIndex >= $cEnd ) {
          continue;
        }

        $prevMatch = $matches[$bIndex - 1][ $matchIndex - 1 ] ?? 0;

        $matches[$bIndex][ $matchIndex ] = $prevMatch + 1;

        if ( $matches[$bIndex][ $matchIndex ] > $maxMatchingWordsInARow ) {
          $maxMatchingWordsInARow = $matches[$bIndex][ $matchIndex ];
          $maxMatchAStart = $bIndex + 1 - $maxMatchingWordsInARow;
          $maxMatchBstart = $matchIndex + 1 - $maxMatchingWordsInARow;
        }
      }
    }

    if ( $maxMatchingWordsInARow === 0 ) {
      return [
        [
          self::DIFF_KEY_REMOVED => array_slice( $b, $bStart, $bEnd - $bStart ),
          self::DIFF_KEY_ADDED => array_slice( $c, $cStart, $cEnd - $cStart ),
        ],
      ];
    }

    return array_merge(
      $this->diffArrays(
        $b,
        $c,
        $bStart,
        $maxMatchAStart,
        $cStart,
        $maxMatchBstart,
        $cValuesAsIndex
      ),
      array_slice( $c, $maxMatchBstart, $maxMatchingWordsInARow ),
      $this->diffArrays(
        $b,
        $c,
        $maxMatchAStart + $maxMatchingWordsInARow,
        $bEnd,
        $maxMatchBstart + $maxMatchingWordsInARow,
        $cEnd,
        $cValuesAsIndex
      )
    );
  }


}
