<?php

namespace WPML\UserInterface\Web\Infrastructure\WordPress\CompositionRoot\Config\Event\Translation\Posts;

use WPML\DicInterface;
use WPML\UserInterface\Web\Core\Component\Notices\WarningTranslationEdit\Application\WarningTranslationEditController;

class PageBuilderEditWarningEvent {

  private $dic;

  private $warningTranslationEditController;


  public function __construct( DicInterface $dic ) {
    $this->dic = $dic;
    $this->register();
  }


  public function register() {
    add_action(
      'wpml_maybe_display_modal_page_builder_warning',
      function( int $postId, string $pageBuilderName, array $args = [] ) {
        $this->getWarningTranslationEditController()->maybeShowPageBuilderWarning( $postId, $pageBuilderName, $args );
      },
      10,
      3
    );

  }


  private function getWarningTranslationEditController(): WarningTranslationEditController {

    if ( $this->warningTranslationEditController === null ) {
      $this->warningTranslationEditController = $this->dic->make(
        WarningTranslationEditController::class
      );
    }

    return $this->warningTranslationEditController;

  }


}
