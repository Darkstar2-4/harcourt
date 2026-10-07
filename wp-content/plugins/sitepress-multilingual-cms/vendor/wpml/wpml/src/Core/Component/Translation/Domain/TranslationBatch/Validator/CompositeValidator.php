<?php

namespace WPML\Core\Component\Translation\Domain\TranslationBatch\Validator;

use WPML\Core\Component\Translation\Domain\TranslationBatch\TranslationBatch;

class CompositeValidator implements ValidatorInterface {

  private $validators;


  public function __construct( array $validators, EmptyMethodsValidator $emptyMethodsValidator ) {
    $this->validators   = $validators;
    $this->validators[] = $emptyMethodsValidator;
  }


  public function validate( TranslationBatch $translationBatch ): array {
    $ignoredElements = [];

    foreach ( $this->validators as $validator ) {
      list( $translationBatch, $newIgnoredElements ) = $validator->validate( $translationBatch );

      if ( ! $translationBatch ) {
        break;
      }

      $ignoredElements = array_merge( $ignoredElements, $newIgnoredElements );
    }

    return [ $translationBatch, $ignoredElements ];
  }


}
