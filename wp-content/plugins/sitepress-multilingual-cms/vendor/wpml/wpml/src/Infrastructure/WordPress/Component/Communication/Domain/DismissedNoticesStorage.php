<?php

namespace WPML\Infrastructure\WordPress\Component\Communication\Domain;

use WPML\Core\Component\Communication\Domain\DismissedNoticesStorageInterface;

class DismissedNoticesStorage implements DismissedNoticesStorageInterface {

  const OPTION_NAME = 'WPML(notices)';
  const USER_META_KEY = 'WPML(notices)';


  public function appendGlobal( string $noticeId ) {
    $dismissedNotices   = $this->getGlobal();
    $dismissedNotices[] = $noticeId;
    $this->saveGlobal( $dismissedNotices );
  }


  public function appendPerUser( string $noticeId, int $userId ) {
    $dismissedNotices   = $this->getPerUser( $userId );
    $dismissedNotices[] = $noticeId;
    $this->savePerUser( $dismissedNotices, $userId );
  }


  public function getGlobal(): array {
    $options = \get_option( self::OPTION_NAME, [] );

    return isset( $options['dismissed'] ) ? $options['dismissed'] : [];
  }


  public function getPerUser( int $userId ): array {
    $meta = \get_user_meta( $userId, self::USER_META_KEY, true );

    return is_array( $meta ) ? $meta : [];
  }


  private function saveGlobal( array $dismissedNotices ) {
    $options = \get_option( self::OPTION_NAME, [] );
    $options['dismissed'] = $dismissedNotices;
    \update_option( self::OPTION_NAME, $options );
  }


  private function savePerUser( array $dismissedNotices, int $userId ) {
    \update_user_meta( $userId, self::USER_META_KEY, $dismissedNotices );
  }


}
