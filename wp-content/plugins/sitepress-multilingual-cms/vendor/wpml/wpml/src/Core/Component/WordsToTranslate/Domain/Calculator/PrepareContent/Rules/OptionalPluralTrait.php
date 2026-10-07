<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules;

trait OptionalPluralTrait {


  protected function removeOptionalPlural( $text ) {
    $patterns = [
        '/\((s|es|aux|er|e|и|y|ات)\)/uiU',
        '/\/(i|che)/uiU',
    ];

    return preg_replace( $patterns, '$1', $text ) ?? '';
  }


}
