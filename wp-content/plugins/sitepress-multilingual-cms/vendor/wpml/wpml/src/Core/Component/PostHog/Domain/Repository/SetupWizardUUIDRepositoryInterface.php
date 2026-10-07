<?php

namespace WPML\Core\Component\PostHog\Domain\Repository;

interface SetupWizardUUIDRepositoryInterface {


  public function save( string $uuid );


  public function get();


}
