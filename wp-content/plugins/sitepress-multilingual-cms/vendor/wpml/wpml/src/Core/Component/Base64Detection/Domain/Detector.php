<?php

namespace WPML\Core\Component\Base64Detection\Domain;

class Detector {

  const MINIMUM_BASE64_LENGTH = 100;


  public function isBase64EncodedText( string $content ): bool {
    if ( empty( $content ) ) {
      return false;
    }

    if ( $this->hasDataUriBase64( $content ) ) {
      return true;
    }

    return $this->isRawBase64( $content );
  }


  public function containsBase64EncodedText( string $content ): bool {
    $decoded = $this->attemptDecode( $content );

    if ( $decoded !== null && $decoded !== $content ) {
      if ( $this->searchForEmbeddedBase64( $decoded ) ) {
        return true;
      }

      if ( $this->hasDataUriBase64( $decoded ) ) {
        return true;
      }
    } else {
      if ( $this->searchForEmbeddedBase64( $content ) ) {
        return true;
      }
    }

    return false;
  }


  private function hasDataUriBase64( string $content ): bool {
    $pattern = '/data:[^;]+;base64,([a-zA-Z0-9+\/\r\n]+=*)/i';

    if ( preg_match_all( $pattern, $content, $matches ) ) {
      foreach ( $matches[1] as $base64Data ) {
        if ( $this->isValidBase64( $base64Data ) ) {
          return true;
        }
      }
    }

    return false;
  }


  private function isRawBase64( string $content ): bool {
    $cleanContent = preg_replace( '/\s+/', '', $content );

    if ( empty( $cleanContent ) ) {
      return false;
    }

    return $this->isValidBase64( $cleanContent );
  }


  private function attemptDecode( string $content ) {
    $cleanContent = preg_replace( '/\s+/', '', $content );

    if ( ! $cleanContent ) {
      return null;
    }

    if ( strlen( $cleanContent ) >= self::MINIMUM_BASE64_LENGTH &&
         preg_match( '/^[a-zA-Z0-9+\/]*={0,2}$/', $cleanContent ) &&
         strlen( $cleanContent ) % 4 === 0 ) {

      $decoded = base64_decode( $cleanContent, true );
      if ( $decoded !== false ) {
        return $decoded;
      }
    }

    return null;
  }


  private function searchForEmbeddedBase64( string $content ): bool {
    if ( $this->hasDataUriBase64( $content ) ) {
      return true;
    }

    $minLengthWithPadding = self::MINIMUM_BASE64_LENGTH - 2;
    $pattern = '/([a-zA-Z0-9+\/]{' . $minLengthWithPadding . ',}={1,2}|' .
               '[a-zA-Z0-9+\/]{' . self::MINIMUM_BASE64_LENGTH . ',})/';

    if ( preg_match_all( $pattern, $content, $matches ) ) {
      foreach ( $matches[1] as $candidate ) {
        if ( $this->isRawBase64( $candidate ) ) {
          return true;
        }
      }
    }

    return false;
  }


  private function isValidBase64( string $data ): bool {
    $cleanData = preg_replace( '/\s+/', '', $data );

    if ( ! $cleanData ) {
      return false;
    }

    if ( strlen( $cleanData ) < self::MINIMUM_BASE64_LENGTH ) {
      return false;
    }

    if ( ! preg_match( '/^[a-zA-Z0-9+\/]*={0,2}$/', $cleanData ) ) {
      return false;
    }

    if ( strlen( $cleanData ) % 4 !== 0 ) {
      return false;
    }

    $decoded = base64_decode( $cleanData, true );
    if ( $decoded === false ) {
      return false;
    }

    $reencoded           = base64_encode( $decoded );
    $normalizedOriginal  = rtrim( $cleanData, '=' );
    $normalizedReencoded = rtrim( $reencoded, '=' );

    return $normalizedOriginal === $normalizedReencoded;
  }


}
