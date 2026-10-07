<?php

namespace WPML\Core\Component\Translation\Application\Query\Priority;

interface StringDataQueryInterface {


  public function getStringsData( array $stringIds ): array;


}
