<?php

namespace WPML\Core\Component\PostHog\Domain\Repository;

interface SetupWizardStartTimeRepositoryInterface {


  public function save( string $wizardUUID, int $timestamp );


  public function get( string $wizardUUID );


}
