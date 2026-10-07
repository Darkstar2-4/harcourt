<?php

namespace WPML\UserInterface\Web\Infrastructure\WordPress\CompositionRoot\Config\Event\ReportContentStats;

use WPML\DicInterface;
use WPML\UserInterface\Web\Infrastructure\WordPress\Events\ReportContentStats\TranslationCompletedEventListener;

class TranslationCompletedEvent {

  const EVENT_NAME = 'wpml_pro_translation_completed';

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
  }


  private function getListener(): TranslationCompletedEventListener {
    if ( $this->listener === null ) {
      $this->listener = $this->dic->make( TranslationCompletedEventListener::class );
    }

    return $this->listener;
  }


}
