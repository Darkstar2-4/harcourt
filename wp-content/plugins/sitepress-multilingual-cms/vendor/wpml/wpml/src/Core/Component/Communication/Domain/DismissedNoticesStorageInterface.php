<?php

namespace WPML\Core\Component\Communication\Domain;

interface DismissedNoticesStorageInterface {


  public function appendGlobal( string $noticeId );


  public function appendPerUser( string $noticeId, int $userId );


  public function getGlobal(): array;


  public function getPerUser( int $userId ): array;


}
