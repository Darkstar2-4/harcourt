<?php

namespace WPML\Core\Port\Remote;

use WPML\PHP\Exception\JsonEncodeException;
use WPML\PHP\Exception\RemoteException;

interface RemoteInterface {


  public function post(
    $url,
    $data,
    $asJson = true,
    $blocking = false,
    $timeout = 1,
    $headers = []
  );


  public function jsonEncode( $data );


}
