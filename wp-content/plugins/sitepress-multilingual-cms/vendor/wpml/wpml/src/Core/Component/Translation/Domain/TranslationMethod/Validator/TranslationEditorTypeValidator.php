<?php

namespace WPML\Core\Component\Translation\Domain\TranslationMethod\Validator;

use WPML\Core\Component\Translation\Domain\TranslationMethod\AutomaticMethod;
use WPML\Core\SharedKernel\Component\Setting\Domain\TranslationEditorSetting;

class TranslationEditorTypeValidator {

  private $translationEditorSetting;


  public function __construct( ?TranslationEditorSetting $translationEditorSetting = null ) {
    $this->translationEditorSetting = $translationEditorSetting;
  }


  public function validate( array $translationMethods ): bool {
    if ( ! count( $translationMethods ) ) {
      return true;
    }

    $currentTranslationEditorType = $this->translationEditorSetting ?
      $this->translationEditorSetting->getValue() :
      null;

    return $currentTranslationEditorType === TranslationEditorSetting::ATE;
  }


}
