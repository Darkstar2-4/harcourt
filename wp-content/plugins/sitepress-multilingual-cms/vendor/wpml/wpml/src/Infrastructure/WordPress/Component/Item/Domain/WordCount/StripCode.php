<?php

namespace WPML\Infrastructure\WordPress\Component\Item\Domain\WordCount;

use WPML\Core\Component\Post\Domain\WordCount\StripCodeInterface;

class StripCode implements StripCodeInterface {


  public function strip( string $content ): string {
    $content = htmlspecialchars_decode( $content );
    $content = $this->stripShortcodes( $content );
    $content = \wp_strip_all_tags( $content );
    $content = $this->stripEmailsAndSpaces( $content );

    return $content;
  }


  private function stripShortcodes( string $content ): string {
    $pattern = '/\[\/?([a-zA-Z0-9_-]+)[^\]]*\](?:(?!\[\/\1\]).)*?(?=\[\/\1\]|\Z)/s';

    $result = preg_replace_callback(
      $pattern,
      function ( $matches ): string {
        return preg_replace( '/\[(\/?)([a-zA-Z0-9_-]+)[^\]]*\]/', '', $matches[0] ) ?: '';
      },
      $content
    );

    return is_string( $result ) ? $result : $content;
  }


  private function stripEmailsAndSpaces( string $content ): string {
    $result = preg_replace(
      [
        '/[^@\s]*@[^@\s]*\.[^@\s]*/',
        '/[0-9\t\n\r\s]+/',
      ],
      '',
      $content
    );

    return is_string( $result ) ? $result : $content;
  }


}
