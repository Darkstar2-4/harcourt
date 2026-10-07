<?php

namespace WPML\Core\Component\PostHog\Domain\Repository;

interface SetupWizardLastStepSubmissionTimeRepositoryInterface {


  public function get();


  public function save( int $timestamp );


}
