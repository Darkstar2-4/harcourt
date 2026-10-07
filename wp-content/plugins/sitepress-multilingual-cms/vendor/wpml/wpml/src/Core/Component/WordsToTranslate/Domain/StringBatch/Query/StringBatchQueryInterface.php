<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\StringBatch\Query;

use WPML\PHP\Exception\InvalidItemIdException;

interface StringBatchQueryInterface {


  public function getStringsIdsById( $id );


}
