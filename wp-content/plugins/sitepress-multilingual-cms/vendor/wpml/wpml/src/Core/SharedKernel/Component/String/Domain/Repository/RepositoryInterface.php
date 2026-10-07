<?php

namespace WPML\Core\SharedKernel\Component\String\Domain\Repository;

use WPML\Core\SharedKernel\Component\String\Domain\StringEntity;
use WPML\PHP\Exception\InvalidArgumentException;
use WPML\PHP\Exception\InvalidItemIdException;

interface RepositoryInterface {


  public function get( int $stringId ): StringEntity;


  public function getBelongingToPackage( int $packageId ): array;


  public function updateField( int $stringId, string $field, $value );


}
