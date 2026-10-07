<?php

namespace WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationDataService;

interface Factory {


  public function createQuery(): QueryInterface;


  public function createCompletedRecordsStorage(): CompletedRecordsStorageInterface;


  public function createProcessor(): ProcessorInterface;


  public function createMarkAsCompletedFunction(): callable;


}
