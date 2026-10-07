<?php

namespace WPML\UserInterface\Web\Core\Component\PostHog\Application\Endpoint\Event\Capture;

use WPML\Core\Component\PostHog\Application\Service\Config\ConfigService;
use WPML\Core\Component\PostHog\Application\Service\Event\CaptureEventService;
use WPML\Core\Component\PostHog\Application\Service\Event\EventInstanceService;
use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\PHP\Exception\RemoteException;

class ProxyCaptureEventController implements EndpointInterface {

  private $configService;

  private $eventInstanceService;

  private $captureEventService;


  public function __construct(
    ConfigService $configService,
    EventInstanceService $eventInstanceService,
    CaptureEventService $captureEventService
  ) {
    $this->configService        = $configService;
    $this->eventInstanceService = $eventInstanceService;
    $this->captureEventService  = $captureEventService;
  }


  public function handle( $requestData = null ): array {
    if (
      ! is_array( $requestData ) ||
      ! array_key_exists( 'distinctId', $requestData ) ||
      ! array_key_exists( 'eventName', $requestData ) ||
      ! array_key_exists( 'eventData', $requestData )
    ) {
      return [
        'success' => false,
        'message' => 'Invalid request data'
      ];
    }

    try {
      $config     = $this->configService->create();
      $isTEAEvent = isset( $requestData['isTEAEvent'] ) && $requestData['isTEAEvent'];

      if ( $isTEAEvent ) {
        $event = $this->eventInstanceService->getCustomTEAEvent(
          $requestData['eventName'],
          $requestData['eventData']
        );
      } else {
        $event = $this->eventInstanceService->getCustomEvent(
          $requestData['eventName'],
          $requestData['eventData']
        );
      }

      $event->addProperties(
        [
        'distinct_id' => $requestData['distinctId'],
         ]
      );

      $result = $this->captureEventService->capture(
        $config,
        $event
      );

      if ( ! $result ) {
        return [
          'success' => false,
          'message' => 'Failed to capture event, make sure PostHog is enabled and distinct_id is set.',
        ];
      }
    } catch ( RemoteException $e ) {
      return [
        'success' => false,
        'message' => $e->getMessage(),
      ];
    }

    return [
      'success' => true,
      'message' => '',
    ];
  }


}
