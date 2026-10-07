<?php

namespace WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationDataService;

interface ProcessorInterface {


  public function process( array $records ): array;


}
