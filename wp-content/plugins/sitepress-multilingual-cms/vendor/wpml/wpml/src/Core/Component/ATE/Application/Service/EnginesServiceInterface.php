<?php

namespace WPML\Core\Component\ATE\Application\Service;

use WPML\Core\Component\ATE\Application\Service\Dto\EngineDto;
use WPML\Core\Component\ATE\Application\Service\Dto\UpdateEngineDto;

interface EnginesServiceInterface {


  public function getList(): array;


  public function update( array $engines );


  public function flushCache();


}
