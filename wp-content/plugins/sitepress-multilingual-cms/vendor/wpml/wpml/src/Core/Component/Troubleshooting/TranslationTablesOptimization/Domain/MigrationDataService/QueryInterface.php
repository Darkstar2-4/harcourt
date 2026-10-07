<?php

namespace WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationDataService;

interface QueryInterface {


  public function countRemaining(): int;


  public function getRemaining( int $limit ): array;


}
