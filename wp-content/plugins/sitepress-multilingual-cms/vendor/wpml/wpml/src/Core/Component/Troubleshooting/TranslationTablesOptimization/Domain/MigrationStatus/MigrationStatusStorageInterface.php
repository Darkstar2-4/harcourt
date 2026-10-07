<?php

namespace WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationStatus;

interface MigrationStatusStorageInterface {


  public function read(): MigrationStatus;


  public function write( MigrationStatus $migrationStatus );


}
