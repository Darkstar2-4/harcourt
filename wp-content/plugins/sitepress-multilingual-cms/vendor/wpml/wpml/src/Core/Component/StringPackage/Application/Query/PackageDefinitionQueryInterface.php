<?php

namespace WPML\Core\Component\StringPackage\Application\Query;

use WPML\Core\Component\StringPackage\Application\Query\Dto\PackageDefinitionDto;

interface PackageDefinitionQueryInterface {


  public function getInfoList(): array;


  public function getNamesList(): array;


}
