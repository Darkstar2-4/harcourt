<?php

namespace WPML\Core\Component\Translation\Application\Query;

use WPML\Core\Component\Translation\Application\Query\Dto\TranslationStatusDto;

interface TranslationStatusQueryInterface {


  public function getByJobIds( array $jobIds, bool $mapStringBatchesOnIndividualStrings = false ): array;


}
