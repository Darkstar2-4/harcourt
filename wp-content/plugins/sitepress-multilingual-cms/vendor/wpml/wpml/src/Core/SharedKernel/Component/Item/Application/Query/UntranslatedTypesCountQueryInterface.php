<?php

namespace WPML\Core\SharedKernel\Component\Item\Application\Query;

use WPML\Core\SharedKernel\Component\Item\Application\Query\Dto\UntranslatedTypeCountDto;

interface UntranslatedTypesCountQueryInterface {
  const KIND_POST = 'post';
  const KIND_PACKAGE = 'package';
  const KIND_STRING = 'string';


  public function forKind();


  public function get( array $queryData = [] ): array;


  public function getSomeIds( $numberOfIdsToFetch, $offset, $type );


}
