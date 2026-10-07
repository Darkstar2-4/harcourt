<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\StringPackage\Query;

use WPML\Core\Component\WordsToTranslate\Domain\Item;
use WPML\Core\Component\WordsToTranslate\Domain\TranslatableDTO;

interface JobQueryInterface {


  public function getContent( Item $stringPackage, string $lang );


  public function useThisContentForItem( $idItem, $content );


}
