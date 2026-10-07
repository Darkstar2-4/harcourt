<?php

namespace WPML\Core\SharedKernel\Component\Translator\Domain\Query;

use WPML\Core\SharedKernel\Component\Translator\Domain\LanguagePair;

interface TranslatorLanguagePairsQueryInterface {


  public function getForSingleTranslator( int $translatorId ): array;


  public function getForManyTranslators( array $translatorsIds ): array;


}
