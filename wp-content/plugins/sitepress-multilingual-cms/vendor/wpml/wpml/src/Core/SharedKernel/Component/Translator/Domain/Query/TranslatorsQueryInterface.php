<?php

namespace WPML\Core\SharedKernel\Component\Translator\Domain\Query;

use WPML\Core\SharedKernel\Component\Translator\Domain\Translator;

interface TranslatorsQueryInterface {


  public function get();


  public function getById( int $id );


  public function getCurrentlyLoggedId();


}
