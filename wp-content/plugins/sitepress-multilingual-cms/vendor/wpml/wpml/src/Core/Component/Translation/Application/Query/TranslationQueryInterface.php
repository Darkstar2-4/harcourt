<?php

namespace WPML\Core\Component\Translation\Application\Query;

use WPML\Core\Component\Translation\Domain\Translation;
use WPML\Core\Component\Translation\Domain\TranslationType;

interface TranslationQueryInterface {


  public function getManyByJobIds( array $jobIds ): array;


  public function getOneByJobId( int $jobId );


  public function getManyByTranslatedElementIds( array $translatedElementIds ): array;


  public function getManyByElementIds(
    TranslationType $translationType,
    array $elementIds
  ): array;


  public function getJobIdsByBatchId( int $batchId ): array;


}
