<?php

namespace WPML\Infrastructure\WordPress\Port\Remote;

use WPML\Core\Port\Remote\RemoteInterface;
use WPML\PHP\Exception\JsonEncodeException;
use WPML\PHP\Exception\RemoteException;

class Remote implements RemoteInterface {


  public function post(
    $url,
    $data,
    $asJson = true,
    $blocking = false,
    $timeout = 1,
    $headers = []
  ) {
    if ( $asJson ) {
      try {
        $data = $this->jsonEncode( $data );
        $headers['Content-Type'] = 'application/json';
      } catch ( JsonEncodeException $e ) {
        throw new RemoteException( 'Failed to encode data to JSON: ' . $e->getMessage() );
      }
    }

    $result = wp_remote_post(
      $url,
      [
        'body'    => $data,
        'headers' => $headers,
        'blocking' => $blocking,
        'timeout'  => $timeout,
        'data_format' => 'body'
      ]
    );

    if ( is_wp_error( $result ) ) {
      throw new RemoteException( 'Remote post request failed: ' . $result->get_error_message() );
    }

    return $result;
  }


  public function jsonEncode( $data ) {
    $encoded = wp_json_encode( $data );

    if ( $encoded === false ) {
      throw new JsonEncodeException( 'Failed to encode data to JSON' );
    }

    return $encoded;
  }


}
