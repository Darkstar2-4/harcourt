<?php

namespace WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Application\Service;

use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationDataService\Factory;

final class MigrateDataService {

  private $factory;


  public function __construct( Factory $factory ) {
    $this->factory = $factory;
  }


  public function initProcessAndGetTotalElements(): int {
    $this->factory->createCompletedRecordsStorage()->create();

    return $this->factory->createQuery()->countRemaining();
  }


  public function run( int $numberOfElementsToProcess ) {
    $records      = $this->factory->createQuery()->getRemaining( $numberOfElementsToProcess );
    $processedIds = $this->factory->createProcessor()->process( $records );
    $this->factory->createCompletedRecordsStorage()->markAsCompleted( $processedIds );
  }


  public function countRemaining(): int {
    return $this->factory->createQuery()->countRemaining();
  }


  public function finalize() {
    $this->factory->createCompletedRecordsStorage()->delete();
    call_user_func( $this->factory->createMarkAsCompletedFunction() );
  }


}
