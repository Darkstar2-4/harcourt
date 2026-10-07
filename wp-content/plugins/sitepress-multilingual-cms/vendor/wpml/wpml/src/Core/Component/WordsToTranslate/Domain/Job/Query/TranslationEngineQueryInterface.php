<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Job\Query;

use WPML\PHP\Exception\RuntimeException;

interface TranslationEngineQueryInterface {


  public function getCostsPerWordForLang( string $langCode, $sourceLang = null );


}
