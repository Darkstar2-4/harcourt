<?php

namespace WPML\UserInterface\Web\Core\Component\Communication\Application\Endpoint;

use WPML\Core\Component\Communication\Application\Service\DismissNoticeService;
use WPML\Core\Port\Endpoint\EndpointInterface;

class DismissNoticeController implements EndpointInterface {

  private $service;


  public function __construct( DismissNoticeService $service ) {
    $this->service = $service;
  }


  public function handle( $requestData = null ): array {
    if ( ! isset( $requestData['noticeId'] ) ) {
      return [
        'success' => false,
        'message' => 'Notice ID is required',
      ];
    }

    $noticeId = is_string( $requestData['noticeId'] ) ? $requestData['noticeId'] : '';
    $noticeId = htmlspecialchars( strip_tags( $noticeId ) );

    if ( empty( $noticeId ) ) {
      return [
        'success' => false,
        'message' => 'Notice ID is required',
      ];
    }

    $this->service->dismiss( $noticeId );

    return [
      'success' => true,
    ];
  }


}
