<?php

namespace WPML\Infrastructure\WordPress\Component\Translation\Application\Query;

use WPML\Core\Component\Translation\Application\Query\Dto\TranslationStatusDto;
use WPML\Core\Component\Translation\Application\Query\TranslationQueryInterface;
use WPML\Core\Component\Translation\Application\Query\TranslationStatusQueryInterface;
use WPML\Core\Component\Translation\Application\String\StringBatchToStringsTranslationsMapper;
use WPML\Core\Component\Translation\Domain\Translation;

class TranslationStatusQuery implements TranslationStatusQueryInterface {

  private $translationQuery;

  private $stringBatchToStringsTranslationMapper;


  public function __construct(
    TranslationQueryInterface $translationQuery,
    StringBatchToStringsTranslationsMapper $stringBatchToStringsTranslationMapper
  ) {
    $this->translationQuery                      = $translationQuery;
    $this->stringBatchToStringsTranslationMapper = $stringBatchToStringsTranslationMapper;
  }


  public function getByJobIds( array $jobIds, bool $mapStringBatchesOnIndividualStrings = false ): array {
    $translations = $this->translationQuery->getManyByJobIds( $jobIds );
    if ( $mapStringBatchesOnIndividualStrings ) {
      $translations = $this->stringBatchToStringsTranslationMapper->map( $translations );
    }

    return array_map(
      function ( Translation $translation ) {
        $reviewStatus = $translation->getReviewStatus();

        return new TranslationStatusDto(
          $translation->getOriginalElementId(),
          $translation->getType()->get(),
          $translation->getTargetLanguageCode(),
          $translation->getStatus()->get(),
          $reviewStatus ? $reviewStatus->getValue() : null
        );
      },
      $translations
    );
  }


}
