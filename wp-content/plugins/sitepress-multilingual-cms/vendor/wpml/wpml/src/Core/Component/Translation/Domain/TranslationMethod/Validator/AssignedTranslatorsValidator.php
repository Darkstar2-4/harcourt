<?php

namespace WPML\Core\Component\Translation\Domain\TranslationMethod\Validator;

use WPML\Core\Component\Translation\Domain\TranslationMethod\LocalTranslatorMethod;
use WPML\Core\SharedKernel\Component\Translator\Domain\Query\TranslatorsQueryInterface;
use WPML\Core\SharedKernel\Component\Translator\Domain\Translator;

class AssignedTranslatorsValidator {

  private $translatorsQuery;

  private $alreadyFetchedTranslators = [];


  public function __construct( TranslatorsQueryInterface $translatorsQuery ) {
    $this->translatorsQuery = $translatorsQuery;
  }


  public function validate( array $translationMethods, string $sourceLanguageCode ): bool {
    if ( ! count( $translationMethods ) ) {
      return true;
    }

    foreach ( $translationMethods as $translatorMethod ) {
      if ( ! $translatorMethod->getTranslatorId() ) {
        continue;
      }

      if ( in_array( $translatorMethod->getTranslatorId(), array_keys( $this->alreadyFetchedTranslators ) ) ) {
        $translator = $this->alreadyFetchedTranslators[ $translatorMethod->getTranslatorId() ];
      } else {
        $translator = $this->translatorsQuery->getById( $translatorMethod->getTranslatorId() );
      }

      if ( ! $translator ) {
        return false;
      }

      $this->alreadyFetchedTranslators[ $translatorMethod->getTranslatorId() ] = $translator;

      if ( ! $this->isAssignedTranslatorStillEligible(
        $translator,
        $sourceLanguageCode,
        $translatorMethod->getTargetLanguageCode()
      ) ) {
        return false;
      }
    }

    return true;
  }


  private function isAssignedTranslatorStillEligible(
    Translator $translator,
    string $sourceLanguageCode,
    string $targetLanguageCode
  ): bool {

    $translatorLanguagePairs = $translator->toArray()['languagePairs'];

    $languagePairsOfSourceLanguageIndex = array_search(
      $sourceLanguageCode,
      array_column(
        $translatorLanguagePairs,
        'from'
      )
    );

    if ( $languagePairsOfSourceLanguageIndex === false ) {
      return false;
    }

    return in_array(
      $targetLanguageCode,
      $translatorLanguagePairs[ $languagePairsOfSourceLanguageIndex ]['to']
    );
  }


}
