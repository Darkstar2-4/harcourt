<?php

namespace WPML\Core\Component\Translation\Domain\TranslationBatch\Validator;

use WPML\Core\Component\Translation\Domain\TranslationBatch\TranslationBatch;

class EmptyMethodsValidator implements ValidatorInterface {


  public function validate( TranslationBatch $translationBatch ): array {
    $targetLanguages = [];
    foreach ( $translationBatch->getTargetLanguages() as $targetLanguage ) {
      if ( $targetLanguage->getElements() ) {
        $targetLanguages[] = $targetLanguage;
      }
    }

    if ( empty( $targetLanguages ) ) {
      return [ null, [] ];
    }

    $translationBatch = $translationBatch->copyWithNewTargetLanguages( $targetLanguages );

    return [ $translationBatch, [] ];
  }


}
