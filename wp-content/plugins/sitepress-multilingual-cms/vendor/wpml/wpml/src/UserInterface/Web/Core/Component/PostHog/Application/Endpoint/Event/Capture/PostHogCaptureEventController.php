<?php

namespace WPML\UserInterface\Web\Core\Component\PostHog\Application\Endpoint\Event\Capture;

use WPML\Core\Component\PostHog\Application\Service\Config\ConfigService;
use WPML\Core\Component\PostHog\Application\Service\Event\CaptureEventService;
use WPML\Core\Component\PostHog\Application\Service\Event\EventInstanceService;
use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\PHP\Exception\RemoteException;

class PostHogCaptureEventController implements EndpointInterface {

  private $configService;

  private $captureEventService;

  private $eventInstanceService;


  public function __construct(
    ConfigService $configService,
    CaptureEventService $captureEventService,
    EventInstanceService $eventInstanceService
  ) {
    $this->configService        = $configService;
    $this->captureEventService  = $captureEventService;
    $this->eventInstanceService = $eventInstanceService;
  }


  public function handle( $requestData = null ): array {
    if (
      ! is_array( $requestData ) ||
      ! array_key_exists( 'eventName', $requestData ) ||
      ! array_key_exists( 'eventProps', $requestData )
    ) {
      return [
        'success' => false,
        'message' => 'Invalid request data'
      ];
    }

    $personProperties = [];

    if (
      array_key_exists( 'personProps', $requestData ) &&
      is_array( $requestData['personProps'] )
    ) {
      $personProperties = $requestData['personProps'];
    }

    try {
      $config = $this->configService->create();

      $event = $this->eventInstanceService->getCustomEvent(
        $requestData['eventName'],
        $requestData['eventProps']
      );

      $result = $this->captureEventService->capture(
        $config,
        $event,
        $personProperties
      );
    } catch ( RemoteException $e ) {
      return [
        'success' => false,
        'message' => 'Failed to capture event: ' . $e->getMessage()
      ];
    }

    return [
      'success' => $result,
      'message' => $result ? 'Event captured successfully' : 'Failed to capture event'
    ];
  }


}
