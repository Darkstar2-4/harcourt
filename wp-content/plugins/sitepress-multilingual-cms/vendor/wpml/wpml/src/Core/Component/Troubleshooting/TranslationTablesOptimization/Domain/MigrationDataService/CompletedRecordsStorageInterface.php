<?php

namespace WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationDataService;

interface CompletedRecordsStorageInterface {


  public function create();


  public function delete();


  public function markAsCompleted( array $recordIds );


}
