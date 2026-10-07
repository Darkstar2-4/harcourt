<?php

namespace WPML\UserInterface\Web\Infrastructure\WordPress\CompositionRoot\Config\Event\ReportContentStats;

use WPML\DicInterface;
use WPML\UserInterface\Web\Infrastructure\WordPress\Events\ReportContentStats\LanguageChangeEventListener;

class LanguageChangeEvent {

  const EVENT_NAME = 'wpml_update_active_languages';
  const DEFAULT_LANGUAGE_EVENT_NAME = 'icl_after_set_default_language';

  private $dic;

  private $listener;


  public function __construct( DicInterface $dic ) {
    $this->dic = $dic;
    $this->register();
  }


  public function register() {
    add_action(
      self::EVENT_NAME,
      function () {
        $this->getListener()->doActions();
      }
    );

    add_action(
      self::DEFAULT_LANGUAGE_EVENT_NAME,
      function () {
        $this->getListener()->doActions();
      }
    );
  }


  private function getListener(): LanguageChangeEventListener {
    if ( $this->listener === null ) {
      $this->listener = $this->dic->make( LanguageChangeEventListener::class );
    }

    return $this->listener;
  }


}
