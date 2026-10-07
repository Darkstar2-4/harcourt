<?php

namespace WPML\UserInterface\Web\Core\Component\Troubleshooting\Application\Endpoint;

use WPML\Core\Component\PostHog\Application\Repository\PostHogStateRepositoryInterface;
use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\Core\Port\PluginInterface;
use WPML\Core\SharedKernel\Component\Setting\Application\Query\TranslationEditorQueryInterface;
use WPML\Core\SharedKernel\Component\WpmlOrgClient\Application\Service\PostHogRecording\PostHogRecordingService;

class UpdatePostHogStateController implements EndpointInterface {

  private $posthogStateRepository;

  private $postHogRecordingService;

  private $plugin;

  private $translationEditorQuery;


  public function __construct(
    PostHogStateRepositoryInterface $posthogStateRepository,
    PostHogRecordingService $postHogRecordingService,
    PluginInterface $plugin,
    TranslationEditorQueryInterface $translationEditorQuery
  ) {
    $this->posthogStateRepository  = $posthogStateRepository;
    $this->postHogRecordingService = $postHogRecordingService;
    $this->plugin                  = $plugin;
    $this->translationEditorQuery  = $translationEditorQuery;
  }


  public function handle( $requestData = null ): array {
    if (
      ! isset( $requestData['enabled'] ) ||
      ! isset( $requestData['siteKey'] ) ||
      ! is_bool( $requestData['enabled'] ) ||
      ! is_string( $requestData['siteKey'] )
    ) {
      return [
        'success' => false,
        'data'    => [
          'message' => 'Invalid request data',
          'enabled' => false,
        ]
      ];
    }

    $teaSetting  = $this->translationEditorQuery->getTranslationEditorSetting();
    $wpmlVersion = $this->plugin->getVersion();
    $teaState    = $teaSetting !== null ? $teaSetting->getValue() : '';
    $enabling    = $requestData['enabled'];

    $result = $this->postHogRecordingService->run(
      $requestData['siteKey'],
      $enabling ? 'force_enable' : 'force_disable',
      $wpmlVersion,
      $teaState
    );

    if ( $result['isResponseError'] ) {
      if ( ! $enabling ) {
        $this->posthogStateRepository->setTrackingMode( 'disabled' );
        return [
          'success' => true,
          'data'    => [
            'message'            => 'PostHog disabled locally; remote update failed',
            'enabled'            => false,
            'trackingMode'       => 'disabled',
            'remoteUpdateFailed' => true,
          ]
        ];
      }

      return [
        'success' => false,
        'data'    => [
          'message' => 'Failed to contact PostHog service',
          'enabled' => false,
        ]
      ];
    }

    $this->posthogStateRepository->setTrackingMode( $result['trackingMode'] );

    return [
      'success' => true,
      'data'    => [
        'message'      => 'PostHog state updated',
        'enabled'      => $result['shouldRecord'],
        'trackingMode' => $result['trackingMode'],
      ]
    ];
  }


}
