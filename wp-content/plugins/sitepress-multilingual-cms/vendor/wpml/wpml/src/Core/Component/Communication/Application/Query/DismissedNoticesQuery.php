<?php

namespace WPML\Core\Component\Communication\Application\Query;

use WPML\Core\Component\Communication\Domain\DismissedNoticesStorageInterface;

class DismissedNoticesQuery {

  private $storage;


  public function __construct( DismissedNoticesStorageInterface $storage ) {
    $this->storage = $storage;
  }


  public function getDismissed( array $noticeIdsToCheck = [] ): array {
    $dismissed = $this->storage->getGlobal();

    if ( ! empty( $noticeIdsToCheck ) ) {
      $dismissed = array_intersect( $dismissed, $noticeIdsToCheck );
    }

    return $dismissed;
  }


  public function getDismissedByUser( int $userId, array $noticeIdsToCheck = [] ): array {
    $dismissed = $this->storage->getPerUser( $userId );

    if ( ! empty( $noticeIdsToCheck ) ) {
      $dismissed = array_intersect( $dismissed, $noticeIdsToCheck );
    }

    return $dismissed;
  }


}
