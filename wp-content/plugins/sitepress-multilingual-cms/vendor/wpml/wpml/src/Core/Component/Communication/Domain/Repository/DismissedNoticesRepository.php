<?php

namespace WPML\Core\Component\Communication\Domain\Repository;

use WPML\Core\Component\Communication\Domain\DismissedNoticesStorageInterface;

class DismissedNoticesRepository {

  private $storage;


  public function __construct( DismissedNoticesStorageInterface $storage ) {
    $this->storage = $storage;
  }


  public function dismiss( string $noticeId ) {
    $dismissedNotices = $this->storage->getGlobal();

    if ( ! in_array( $noticeId, $dismissedNotices ) ) {
      $this->storage->appendGlobal( $noticeId );
    }
  }


  public function dismissPerUser( string $noticeId, int $userId ) {
    $dismissedNotices = $this->storage->getPerUser( $userId );

    if ( ! in_array( $noticeId, $dismissedNotices ) ) {
      $this->storage->appendPerUser( $noticeId, $userId );
    }
  }


}
