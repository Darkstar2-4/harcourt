<?php

namespace WPML\Infrastructure\WordPress\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules;

use WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules\ShortcodeInterface;

class Shortcode implements ShortcodeInterface {


  public function removeShortcodes( string $content ): string {

    do {
      $previousContent = $content;
      $content = preg_replace( '/\[([a-z0-9_-]+)(?:\s[^\]]*)?\](.*?)\[\/\1\]/is', '$2', $content ) ?? '';
    } while ( $content !== $previousContent );

    $content = preg_replace( '/\[[a-z0-9_-]+(?:\s[^\]]*)?\/\]/i', '', $content ) ?? '';

    return $content;
  }


}
