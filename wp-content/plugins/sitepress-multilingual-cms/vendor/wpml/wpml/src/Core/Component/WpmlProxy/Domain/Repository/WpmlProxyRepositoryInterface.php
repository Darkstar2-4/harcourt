<?php

namespace WPML\Core\Component\WpmlProxy\Domain\Repository;

interface WpmlProxyRepositoryInterface {


  public function isEnabled(): bool;


  public function setIsEnabled( bool $isEnabled );


}
