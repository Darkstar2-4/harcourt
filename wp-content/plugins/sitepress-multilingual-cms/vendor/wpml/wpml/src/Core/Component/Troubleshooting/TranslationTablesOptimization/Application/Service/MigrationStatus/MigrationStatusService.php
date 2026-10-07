<?php

namespace WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Application\Service\MigrationStatus;

use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationStatus\MigrationStatus;
use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationStatus\MigrationStatusStorageInterface;
use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationStatus\PreliminaryConditionQueryInterface;

class MigrationStatusService {

  private $storage;

  private $preliminaryConditionQuery;


  public function __construct(
    MigrationStatusStorageInterface $storage,
    PreliminaryConditionQueryInterface $preliminaryConditionQuery
  ) {
    $this->storage                   = $storage;
    $this->preliminaryConditionQuery = $preliminaryConditionQuery;
  }


  public function getMigrationStatus(): MigrationStatusDTO {
    $status = $this->storage->read();

    if ( $status->isTotalProcessCompleted() ) {
      return MigrationStatusDTO::from( $status );
    }

    if ( ! $this->preliminaryConditionQuery->hasNonNullTranslationPackages() ) {
      $status = MigrationStatus::createCompletedStatus();
      $this->storage->write( $status );
    }

    return MigrationStatusDTO::from( $status );
  }


  public function markPrevStateCompleted() {
    $status = $this->storage->read();
    $status->setPrevStateCompleted( true );
    $this->storage->write( $status );
  }


  public function markTranslationPackageCompleted() {
    $status = $this->storage->read();
    $status->setTranslationPackageCompleted( true );
    $this->storage->write( $status );
  }


  public function markObsoleteTranslationElementsRemovalCompleted() {
    $status = $this->storage->read();
    $status->setObsoleteTranslationElementsRemovalCompleted( true );
    $this->storage->write( $status );
  }


  public function markTranslationElementsCompressionCompleted() {
    $status = $this->storage->read();
    $status->setTranslationElementsCompressionCompleted( true );
    $this->storage->write( $status );
  }


  public function markTranslationElementsCompressionFixedCompleted() {
    $status = $this->storage->read();
    $status->setTranslationElementsCompressionFixedCompleted( true );
    $this->storage->write( $status );
  }


}
