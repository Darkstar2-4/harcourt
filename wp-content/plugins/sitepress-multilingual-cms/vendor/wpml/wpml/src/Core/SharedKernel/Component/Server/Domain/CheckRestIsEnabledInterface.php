<?php

namespace WPML\Core\SharedKernel\Component\Server\Domain;

interface CheckRestIsEnabledInterface {


  public function isEnabled( bool $useCache = false ): bool;


  public function getEndpoint(): string;


}
