<?php

namespace WPML\UserInterface\Web\Infrastructure\WordPress\CompositionRoot\Config\Event\Translation\StartUsingDashboardBanner;

use WPML\DicInterface;
use WPML\UserInterface\Web\Infrastructure\WordPress\Events\Translation\StartUsingDashboardBanner\BackFromATEManualTranslationListener;
use WPML\UserInterface\Web\Infrastructure\WordPress\Events\Translation\StartUsingDashboardBanner\TranslationCreatedInDashboardListener;

class Events {

  private $dic;

  private $backFromATEManualTranslationListener;

  private $translationCreatedInDashboardListener;


  public function __construct( DicInterface $dic ) {
    $this->dic = $dic;
    $this->register();
  }


  public function register() {
    add_action(
      'wpml_on_back_from_ate_manual_translation',
      function () {
        $this->getBackFromATEManualTranslationListener()->recordTranslation();
      }
    );

    add_action(
      'wpml_translations_sent_from_dashboard',
      function () {
        $this->getTranslationCreatedInDashboardListener()->recordTranslation();
      }
    );
  }


  private function getBackFromATEManualTranslationListener(): BackFromATEManualTranslationListener {
    if ( $this->backFromATEManualTranslationListener === null ) {
      $this->backFromATEManualTranslationListener = $this->dic->make( BackFromATEManualTranslationListener::class );
    }

    return $this->backFromATEManualTranslationListener;
  }


  private function getTranslationCreatedInDashboardListener(): TranslationCreatedInDashboardListener {
    if ( $this->translationCreatedInDashboardListener === null ) {
      $this->translationCreatedInDashboardListener = $this->dic->make( TranslationCreatedInDashboardListener::class );
    }

    return $this->translationCreatedInDashboardListener;
  }


}
