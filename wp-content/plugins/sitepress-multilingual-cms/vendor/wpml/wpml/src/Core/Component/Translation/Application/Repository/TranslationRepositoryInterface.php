<?php

namespace WPML\Core\Component\Translation\Application\Repository;

use WPML\Core\Component\Translation\Domain\Translation;
use WPML\Core\Component\Translation\Domain\TranslationType;

interface TranslationRepositoryInterface {


  public function get( TranslationType $itemType, string $elementType, int $elementId ): Translation;


  public function saveElementLanguage(
    TranslationType $itemType,
    string $elementType,
    int $elementId,
    string $languageCode,
    ?string $sourceLanguageCode = null,
    ?int $trid = null
  );


  public function setCancelledStatus( int $translationId ): int;


}
