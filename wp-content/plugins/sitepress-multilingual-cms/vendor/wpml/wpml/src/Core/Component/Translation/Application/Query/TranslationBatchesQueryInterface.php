<?php

namespace WPML\Core\Component\Translation\Application\Query;

use WPML\Core\Component\Translation\Application\Query\Dto\TranslationBatchDto;

interface TranslationBatchesQueryInterface {


  public function getTotalCount (): int;


  public function getByNameStartsWith ( string $searchName ): array;


  public function getNeedsReviewJobsBatchType(): array;


}
