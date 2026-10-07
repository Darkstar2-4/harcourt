<?php

namespace WPML\Infrastructure\WordPress\SharedKernel\WpmlOrgClient\Domain\Api\Endpoints\PostHogRecording;

use WPML\Core\Component\PostHog\Domain\TrackingMode;
use WPML\Core\SharedKernel\Component\WpmlOrgClient\Domain\Api\ApiUrl;
use WPML\Core\SharedKernel\Component\WpmlOrgClient\Domain\Api\Endpoints\PostHogRecordingInterface;

class PostHogRecording implements PostHogRecordingInterface {

  const ENDPOINT = '/?action=should_record_site';

  private $apiUrl;


  public function __construct( ApiUrl $apiUrl ) {
    $this->apiUrl = $apiUrl;
  }


  public function run(
    string $siteKey,
    string $recordingMode = 'default',
    string $wpmlVersion = '',
    string $teaState = ''
  ): array {
    $body = [
      'site_key'       => $siteKey,
      'recording_mode' => $recordingMode,
    ];

    if ( $wpmlVersion !== '' ) {
      $body['wpml_version'] = $wpmlVersion;
    }

    if ( $teaState !== '' ) {
      $body['tea_state'] = $teaState;
    }

    $response = wp_remote_post(
      $this->apiUrl->get() . self::ENDPOINT,
      [ 'body' => $body ]
    );

    if ( is_wp_error( $response ) ) {
      return [
        'success'         => false,
        'shouldRecord'    => false,
        'trackingMode'    => TrackingMode::DISABLED,
        'isResponseError' => true,
      ];
    }

    $responseCode = wp_remote_retrieve_response_code( $response );
    $body         = wp_remote_retrieve_body( $response );
    $decodedBody  = json_decode( $body, true );

    if ( $responseCode !== 200 || ! is_array( $decodedBody ) ) {
      return [
        'success'         => false,
        'shouldRecord'    => false,
        'trackingMode'    => TrackingMode::DISABLED,
        'isResponseError' => true,
      ];
    }

    $trackingMode = $this->parseTrackingMode( $decodedBody );

    return [
      'success'         => true,
      'shouldRecord'    => TrackingMode::toBool( $trackingMode ),
      'trackingMode'    => $trackingMode,
      'isResponseError' => false,
    ];
  }


  private function parseTrackingMode( array $decodedBody ): string {
    if ( isset( $decodedBody['tracking_mode'] ) && is_string( $decodedBody['tracking_mode'] ) ) {
      $mode = $decodedBody['tracking_mode'];

      if ( TrackingMode::isValid( $mode ) ) {
        return $mode;
      }
    }

    if ( isset( $decodedBody['should_record'] ) ) {
      return TrackingMode::fromBool( (bool) $decodedBody['should_record'] );
    }

    return TrackingMode::DISABLED;
  }


}
