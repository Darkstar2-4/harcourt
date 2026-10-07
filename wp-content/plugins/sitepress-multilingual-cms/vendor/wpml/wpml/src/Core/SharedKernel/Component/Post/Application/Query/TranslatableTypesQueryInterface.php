<?php

namespace WPML\Core\SharedKernel\Component\Post\Application\Query;

use WPML\Core\SharedKernel\Component\Post\Application\Query\Dto\PostTypeDto;

interface TranslatableTypesQueryInterface {


  public function getTranslatable(): array;


  public function getDisplayAsTranslated(): array;


  public function getTranslatableWithoutDisplayAsTranslated(): array;


}
