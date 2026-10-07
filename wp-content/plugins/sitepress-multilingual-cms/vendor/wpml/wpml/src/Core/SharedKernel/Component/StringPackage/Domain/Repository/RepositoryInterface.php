<?php

namespace WPML\Core\SharedKernel\Component\StringPackage\Domain\Repository;

use WPML\PHP\Exception\InvalidArgumentException;

interface RepositoryInterface {


  public function updateField( int $packageId, string $field, $value );


}
