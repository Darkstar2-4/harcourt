<?php

namespace WPML\Core\SharedKernel\Component\String\Application\Repository;

use WPML\Core\SharedKernel\Component\String\Domain\StringTranslation;

interface StringBatchRepositoryInterface {


  public function getStringTranslationsByBatch( int $batchId, string $targetLanguage ): array;


  public function deleteStringTranslationsByIds( array $translationIds ): int;


  public function updateStringTranslationsStatus( array $translationIds, int $status ): int;


  public function deleteStringBatch( int $batchId ): int;


}
