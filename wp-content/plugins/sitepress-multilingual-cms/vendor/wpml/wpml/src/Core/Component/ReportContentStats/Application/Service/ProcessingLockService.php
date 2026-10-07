<?php

namespace WPML\Core\Component\ReportContentStats\Application\Service;

use WPML\Core\Component\ReportContentStats\Domain\Repository\ProcessingLockRepositoryInterface;

class ProcessingLockService {

  private $lockRepository;


  public function __construct( ProcessingLockRepositoryInterface $lockRepository ) {
    $this->lockRepository = $lockRepository;
  }


  public function isLocked(): bool {
    $lock = $this->lockRepository->get();

    return $lock && $lock->isActive();
  }


  public function isLockedByOthers( $ownerId ): bool {
    $lock = $this->lockRepository->get();
    if ( ! $lock || ! $lock->isActive() ) {
      return false;
    }

    if ( ! $ownerId ) {
      return true;
    }

    return ! $lock->isOwnedBy( $ownerId );
  }


  public function acquire() {
    $acquired = $this->lockRepository->acquire();
    if ( ! $acquired ) {
      return null;
    }

    $lock = $this->lockRepository->get();

    return $lock ? $lock->getOwnerId() : null;
  }


  public function release() {
    $this->lockRepository->release();
  }


  public function refresh( $ownerId ): bool {
    if ( ! $ownerId ) {
      return false;
    }

    $lock = $this->lockRepository->get();
    if ( ! $lock || ! $lock->isOwnedBy( $ownerId ) ) {
      return false;
    }

    $extendedLock = $lock->extend();
    $this->lockRepository->update( $extendedLock );

    return true;
  }


}
