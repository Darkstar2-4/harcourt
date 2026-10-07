<?php

namespace WPML\Infrastructure\WordPress\Component\PostHog\Domain\Repository;

use WPML\Core\Component\PostHog\Domain\Repository\SetupWizardUUIDRepositoryInterface;
use WPML\Core\Port\Persistence\OptionsInterface;

class SetupWizardUUIDRepository implements SetupWizardUUIDRepositoryInterface {

  const OPTION_NAME = 'wpml_ph_wizard_uuid';

  private $options;


  public function __construct( OptionsInterface $options ) {
    $this->options = $options;
  }


  public function save( string $uuid ) {
    $this->options->save( self::OPTION_NAME, $uuid );
  }


  public function get() {
    $uuid = $this->options->get( self::OPTION_NAME );

    return $uuid;
  }


}
