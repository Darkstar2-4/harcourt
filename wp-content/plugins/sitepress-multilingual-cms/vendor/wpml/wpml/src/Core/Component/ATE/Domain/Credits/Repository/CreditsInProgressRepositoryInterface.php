<?php

namespace WPML\Core\Component\ATE\Domain\Credits\Repository;

interface CreditsInProgressRepositoryInterface {


  public function getCreditsInProgressCount( $statusesInProgress );


}
