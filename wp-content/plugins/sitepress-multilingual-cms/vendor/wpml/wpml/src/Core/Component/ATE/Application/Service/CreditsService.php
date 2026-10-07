<?php

namespace WPML\Core\Component\ATE\Application\Service;

use WPML\Core\Component\ATE\Domain\Credits\CreditsInProgress;
use WPML\Core\Component\ATE\Domain\Credits\CreditsInProgressDTO;

class CreditsService {

  private $creditsInProgress;


  public function __construct( CreditsInProgress $creditsInProgress ) {
    $this->creditsInProgress = $creditsInProgress;
  }


  public function getCreditsInProgress() {
    return $this->creditsInProgress->getCount();
  }


}
