<?php

namespace WPML\Core\Component\Translation\Application\String\Query;

interface StringsFromBatchQueryInterface {


  public function get( int $batchId ): array;


}
