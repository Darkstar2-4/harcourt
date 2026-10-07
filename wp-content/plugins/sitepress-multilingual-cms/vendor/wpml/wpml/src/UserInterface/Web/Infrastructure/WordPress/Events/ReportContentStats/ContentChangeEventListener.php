<?php

namespace WPML\UserInterface\Web\Infrastructure\WordPress\Events\ReportContentStats;

use WPML\Core\Component\ReportContentStats\Application\Service\ContentChangeDetectionService;

class ContentChangeEventListener {

  private $contentChangeDetectionService;


  public function __construct( ContentChangeDetectionService $contentChangeDetectionService ) {
    $this->contentChangeDetectionService = $contentChangeDetectionService;
  }


  public function onTransitionPostStatus( string $newStatus, string $oldStatus, $post ): void {
    if ( $post->post_type === 'revision' || $post->post_type === 'attachment' ) {
      return;
    }

    if ( $this->publishedPostCountChanged( $newStatus, $oldStatus ) ) {
      $this->contentChangeDetectionService->check();
    }
  }


  private function publishedPostCountChanged( string $newStatus, string $oldStatus ): bool {
    return $newStatus !== $oldStatus
      && ( $newStatus === 'publish' || $oldStatus === 'publish' );
  }


}
