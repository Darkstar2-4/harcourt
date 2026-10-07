<?php

namespace WPML\Core\Component\Communication\Application\Service;

use WPML\Core\Component\Communication\Domain\Repository\DismissedNoticesRepository;

class DismissNoticeService {

  private $repository;


  public function __construct( DismissedNoticesRepository $repository ) {
    $this->repository = $repository;
  }


  public function dismiss( string $noticeId ) {
    $this->repository->dismiss( $noticeId );
  }


  public function dismissPerUser( string $noticeId, int $userId ) {
    $this->repository->dismissPerUser( $noticeId, $userId );
  }


}
