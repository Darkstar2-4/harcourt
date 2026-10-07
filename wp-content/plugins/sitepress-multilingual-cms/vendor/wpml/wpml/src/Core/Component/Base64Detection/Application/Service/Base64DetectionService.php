<?php

namespace WPML\Core\Component\Base64Detection\Application\Service;

use WPML\Core\Component\Base64Detection\Domain\Detector;

class Base64DetectionService {

  private $base64Detector;


  public function __construct( Detector $base64Detector ) {
    $this->base64Detector = $base64Detector;
  }


  public function isBase64EncodedText( string $content ): bool {
    return $this->base64Detector->isBase64EncodedText( $content );
  }


  public function containsBase64EncodedText( string $content ): bool {
    return $this->base64Detector->containsBase64EncodedText( $content );
  }


}
