<?php

namespace WPML\Infrastructure\WordPress\Component\Troubleshooting\TranslationTablesOptimization\Domain\PreviousState;

use WPML\Core\Component\Translation\Domain\PreviousState\DataCompressInterface;
use WPML\Core\Component\Translation\Domain\PreviousState\PreviousState;
use WPML\Core\Component\Translation\Domain\PreviousState\PreviousStateRepositoryInterface;
use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationDataService\ProcessorInterface;
use WPML\PHP\Exception\InvalidItemIdException;

class Processor implements ProcessorInterface {

  private $repository;

  private $dataCompress;


  public function __construct(
    PreviousStateRepositoryInterface $repository,
    DataCompressInterface $dataCompress
  ) {
    $this->repository   = $repository;
    $this->dataCompress = $dataCompress;
  }


  public function process( array $records ): array {
    $processed = [];

    foreach ( $records as $record ) {
      $data = $this->dataCompress->decompress( $record['previousState'] );
      if ( empty( $data ) ) {
        $processed[] = $record['translationId'];
        continue;
      }

      $previousState = PreviousState::fromArray( $data );
      try {
        $this->repository->update( $record['translationId'], $previousState );
      } catch ( InvalidItemIdException $e ) {
      } finally {
        $processed[] = $record['translationId'];
      }
    }

    return $processed;
  }


}
