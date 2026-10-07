<?php

namespace WPML\Infrastructure\WordPress\Component\PostHog\Domain\Event\SetupWizard;

use WPML\Core\Component\PostHog\Domain\Event\SetupWizard\SetupWizardUUIDInterface;
use WPML\Core\Component\PostHog\Domain\Repository\SetupWizardUUIDRepositoryInterface;

class SetupWizardUUID implements SetupWizardUUIDInterface {

  private $uuidRepository;


  public function __construct( SetupWizardUUIDRepositoryInterface $uuidRepository ) {
    $this->uuidRepository = $uuidRepository;
  }


  public function create(): string {
    if ( $uuid = $this->uuidRepository->get() ) {
      return $uuid;
    }

    $uuid = $this->generateUUID4();
    $this->uuidRepository->save( $uuid );

    return $uuid;
  }


  protected function generateUUID4(): string {
    return wp_generate_uuid4();
  }


}
