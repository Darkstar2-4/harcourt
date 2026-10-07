<?php

namespace WPML\Core\Component\Translation\Application\Query;

use WPML\Core\Component\Translation\Domain\TranslationType;

interface ItemLanguageQueryInterface {


  public function getManyOriginalLanguagesOfItems( array $items ): array;


}
