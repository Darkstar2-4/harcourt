<?php

namespace WPML\Core\Component\Translation\Domain\Settings;

use WPML\Core\SharedKernel\Component\Setting\Domain\TranslationEditorSetting;

class Settings {

  private $isTMAllowed;

  private $reviewMode;

  private $translationEditor;

  private $translateEverything;

  private $translateAutomaticallyPerPostType;


  public function __construct(
    bool $isTMAllowed,
    TranslateEverything $translateEverything,
    TranslateAutomaticallyPerPostType $translateAutomaticallyPerPostType,
    ?ReviewMode $reviewMode = null,
    ?TranslationEditorSetting $translationEditor = null
  ) {
    $this->isTMAllowed                       = $isTMAllowed;
    $this->translateEverything               = $translateEverything;
    $this->translateAutomaticallyPerPostType = $translateAutomaticallyPerPostType;
    $this->reviewMode                        = $reviewMode;
    $this->translationEditor                 = $translationEditor;
  }


  public function isTMAllowed(): bool {
    return $this->isTMAllowed;
  }


  public function getReviewMode() {
    return $this->reviewMode;
  }


  public function getTranslationEditor() {
    return $this->translationEditor;
  }


  public function getTranslateEverything(): TranslateEverything {
    return $this->translateEverything;
  }


  public function getTranslateAutomaticallyPerPostType(): TranslateAutomaticallyPerPostType {
    return $this->translateAutomaticallyPerPostType;
  }


  public function enableTranslateEverything(
    ?ReviewMode $reviewMode = null,
    ?TranslationEditorSetting $translationEditor = null
  ): self {
    if ( ! $this->isTMAllowed() ) {
      throw new SettingsException( 'TM is not allowed' );
    }

    if ( ! $reviewMode ) {
      $reviewMode = $this->getReviewMode() ?: ReviewMode::createDefault();
    }

    if ( ! $translationEditor ) {
      $translationEditor = $this->getTranslationEditor() ?: TranslationEditorSetting::createDefault();
    }

    $translateEverything = clone $this->getTranslateEverything();
    $translateEverything->enable();

    return new self(
      $this->isTMAllowed(),
      $translateEverything,
      $this->getTranslateAutomaticallyPerPostType(),
      $reviewMode,
      $translationEditor
    );
  }


  public function disableTranslateEverything(): self {
    $translateEverything = clone $this->getTranslateEverything();
    $translateEverything->disable();

    return new self(
      $this->isTMAllowed(),
      $translateEverything,
      $this->getTranslateAutomaticallyPerPostType(),
      $this->reviewMode,
      $this->translationEditor
    );
  }


  public function enableATE(): self {
    return new self(
      $this->isTMAllowed(),
      $this->translateEverything,
      $this->getTranslateAutomaticallyPerPostType(),
      $this->reviewMode,
      new TranslationEditorSetting( TranslationEditorSetting::ATE )
    );
  }


}
