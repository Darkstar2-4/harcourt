<?php

namespace WPML\Infrastructure\WordPress\Component\Troubleshooting\TranslationTablesOptimization\Domain\TranslationElements\CompressFix;

use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Application\Service\MigrationStatus\MigrationStatusService;
use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationDataService\CompletedRecordsStorageInterface;
use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationDataService\ProcessorInterface;
use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationDataService\QueryInterface;
use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\TranslationElements\CompressFix\Factory as CompressFixFactory;
use WPML\Core\Port\Persistence\DatabaseSchemaInfoInterface;
use WPML\Infrastructure\WordPress\Component\Troubleshooting\TranslationTablesOptimization\Domain\TranslationElements\Compress\CompletedRecordsStorage;
use WPML\Infrastructure\WordPress\Component\Troubleshooting\TranslationTablesOptimization\Domain\TranslationElements\Compress\Query;

class Factory implements CompressFixFactory {

  private $databaseSchemaInfo;

  private $wpdb;

  private $migrationStatusService;


  public function __construct(
    DatabaseSchemaInfoInterface $databaseSchemaInfo,
    \wpdb $wpdb,
    MigrationStatusService $migrationStatusService
  ) {
    $this->databaseSchemaInfo = $databaseSchemaInfo;
    $this->wpdb               = $wpdb;
    $this->migrationStatusService = $migrationStatusService;
  }


  public function createQuery(): QueryInterface {
    return new Query(
      $this->wpdb
    );
  }


  public function createCompletedRecordsStorage(): CompletedRecordsStorageInterface {
    return new CompletedRecordsStorage(
      $this->wpdb,
      $this->databaseSchemaInfo
    );
  }


  public function createProcessor(): ProcessorInterface {
    return new FixDoubleCompressionProcessor(
      $this->wpdb
    );
  }


  public function createMarkAsCompletedFunction(): callable {
    return [$this->migrationStatusService, 'markTranslationElementsCompressionFixedCompleted'];
  }


}
