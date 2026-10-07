<?php

namespace WPML\Infrastructure\WordPress\Component\ReportContentStats\Domain\Repository;

use WPML\Core\Component\ReportContentStats\Domain\ProcessingLock;
use WPML\Core\Component\ReportContentStats\Domain\Repository\ProcessingLockRepositoryInterface;
use WPML\Core\Port\Persistence\OptionsInterface;

class ProcessingLockRepository implements ProcessingLockRepositoryInterface {

  const LOCK_OPTION_KEY = 'wpml-content-stats-processing-lock';

  private $options;


  public function __construct( OptionsInterface $options ) {
    $this->options = $options;
  }


  public function acquire(): bool {
    $existingLock = $this->get();
    if ( $existingLock ) {
      if ( $existingLock->hasExpired() ) {
        $this->release();

        return $this->acquire();
      }

      return false;
    }

    $newLock = ProcessingLock::create();
    $data    = $this->serialize( $newLock );

    return $this->options->add( self::LOCK_OPTION_KEY, $data, false );
  }


  public function get() {
    $data = $this->options->get( self::LOCK_OPTION_KEY );

    if ( ! $data || ! is_string( $data ) ) {
      return null;
    }

    return $this->deserialize( $data );
  }


  public function release() {
    $this->options->delete( self::LOCK_OPTION_KEY );
  }


  public function update( ProcessingLock $lock ) {
    $data = $this->serialize( $lock );
    $this->options->save( self::LOCK_OPTION_KEY, $data, false );
  }


  private function serialize( ProcessingLock $lock ): string {
    $data = [
      'timestamp' => $lock->getAcquiredAt(),
      'ownerId'   => $lock->getOwnerId(),
    ];

    $json = function_exists( 'wp_json_encode' )
      ? wp_json_encode( $data )
      : json_encode( $data );

    return $json !== false ? $json : '{}';
  }


  private function deserialize( $data ) {
    $decoded = json_decode( $data, true );
    if ( ! is_array( $decoded ) || ! isset( $decoded['timestamp'] ) ) {
      return null;
    }

    return new ProcessingLock(
      (int) $decoded['timestamp'],
      $decoded['ownerId'] ?? null
    );
  }


}
