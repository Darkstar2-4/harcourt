<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent\Rules;

trait HTMLTrait {

  private $translatableHTMLAttributes = [
    'alt',
    'title',
    'placeholder',
    'aria-label',
    'value',
  ];


  private function removeHTMLExceptTranslatableAttributes( $html ) {
    $html = $this->removeHiddenInputs( $html );

    $attributes = function_exists( 'libxml_use_internal_errors' )
      ? $this->getTranslatableHTMLAttributesUseDOMDocument( $html )
      : $this->getTranslatableHTMLAttributeTextsUsePregMatch( $html );

    $html = html_entity_decode( $html );
    $html = preg_replace( '/<!--.*?-->/s', '', $html ) ?: '';
    $html = preg_replace( '/<style[\s\S]*?<\/style>/i', '', $html ) ?: '';
    $html = preg_replace( '/<script[\s\S]*?<\/script>/i', '', $html ) ?: '';
    $html = strip_tags( $html );

    return $html . ' ' . implode( ' ', $attributes );
  }


  private function removeHiddenInputs( $html ) {
    $html = preg_replace(
      '/<input\b[^>]*\btype=["\']?hidden["\']?[^>]*>/i',
      '',
      $html
    ) ?: '';

    return $html;
  }


  private function getTranslatableHTMLAttributeTextsUsePregMatch( $html ) {
    preg_match_all(
      '/\b(?:' . implode( '|', $this->translatableHTMLAttributes ) .')\s*=\s*(["\'])(.*?)\1/i',
      $html,
      $matches
    );

    return array_map( 'trim', $matches[2] );
  }


  private function getTranslatableHTMLAttributesUseDOMDocument( $html ) {
    if (
      ! preg_match(
        '/\s*' . implode( '|', $this->translatableHTMLAttributes ) . '\s*=/i',
        $html
      )
    ) {
      return [];
    }

    $texts = [];
    $doc = new \DOMDocument();
    libxml_use_internal_errors( true );
    $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
    libxml_clear_errors();
    $xpath = new \DOMXPath( $doc );

    foreach ( $this->translatableHTMLAttributes as $attr ) {
      if ( stripos( $html, $attr ) === false ) {
        continue;
      }

      $nodes = $xpath->query( '//*[@' . $attr . ']' );
      if ( ! $nodes || $nodes->length === 0 ) {
        continue;
      }
      foreach ( $nodes as $node ) {
        $texts[] = $node->getAttribute( $attr );
      }
    }

    return $texts;
  }


}
