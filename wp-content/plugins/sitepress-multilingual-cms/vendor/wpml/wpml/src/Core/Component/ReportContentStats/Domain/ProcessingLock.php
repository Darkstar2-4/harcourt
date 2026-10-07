<?php

namespace WPML\Core\Component\ReportContentStats\Domain;

class ProcessingLock {

  const LOCK_TIMEOUT_SECONDS = 30;

  private $acquiredAt;

  private $ownerId;


  public function __construct( $acquiredAt = null, $ownerId = null ) {
    $this->acquiredAt = $acquiredAt;
    $this->ownerId    = $ownerId;
  }


  public static function create(): ProcessingLock {
    return new self( time(), self::generateOwnerId() );
  }


  private static function generateOwnerId(): string {
    if ( function_exists( 'wp_generate_uuid4' ) ) {
      return wp_generate_uuid4();
    }

    return sprintf(
      '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
      mt_rand( 0, 0xffff ),
      mt_rand( 0, 0xffff ),
      mt_rand( 0, 0xffff ),
      mt_rand( 0, 0x0fff ) | 0x4000,
      mt_rand( 0, 0x3fff ) | 0x8000,
      mt_rand( 0, 0xffff ),
      mt_rand( 0, 0xffff ),
      mt_rand( 0, 0xffff )
    );
  }


  public function isActive(): bool {
    if ( $this->acquiredAt === null ) {
      return false;
    }

    return time() - $this->acquiredAt < self::LOCK_TIMEOUT_SECONDS;
  }


  public function hasExpired(): bool {
    if ( $this->acquiredAt === null ) {
      return true;
    }

    return time() - $this->acquiredAt >= self::LOCK_TIMEOUT_SECONDS;
  }


  public function extend(): ProcessingLock {
    return new self( time(), $this->ownerId );
  }


  public function getAcquiredAt() {
    return $this->acquiredAt;
  }


  public function getOwnerId() {
    return $this->ownerId;
  }


  public function isOwnedBy( string $ownerId ): bool {
    return $this->ownerId === $ownerId;
  }


}
