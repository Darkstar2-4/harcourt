<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Strings\Query;

use WPML\Core\Component\WordsToTranslate\Domain\Item;

interface TranslationQueryInterface {


  public function getLastTranslatedOriginalContent( Item $string, string $lang );


}
