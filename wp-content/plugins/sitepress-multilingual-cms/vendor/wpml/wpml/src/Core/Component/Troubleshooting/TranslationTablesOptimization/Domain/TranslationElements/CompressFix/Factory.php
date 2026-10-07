<?php

namespace WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\TranslationElements\CompressFix;

use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationDataService\CompletedRecordsStorageInterface;
use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationDataService\Factory as BaseFactory;
use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationDataService\ProcessorInterface;
use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationDataService\QueryInterface;

interface Factory extends BaseFactory {


  public function createQuery(): QueryInterface;


  public function createCompletedRecordsStorage(): CompletedRecordsStorageInterface;


  public function createProcessor(): ProcessorInterface;


}
