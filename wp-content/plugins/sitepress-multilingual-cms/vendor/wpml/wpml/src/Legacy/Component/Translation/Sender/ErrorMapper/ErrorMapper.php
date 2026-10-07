<?php

namespace WPML\Legacy\Component\Translation\Sender\ErrorMapper;

class ErrorMapper {

  private $strategies;


  public function __construct( array $strategies ) {
    $this->strategies = $strategies;
  }


  public function map( array $errors ): string {
    foreach ( $this->strategies as $strategy ) {
      $message = $strategy->map( $errors );
      if ( $message ) {
        return $message;
      }
    }

    if ( count( $errors ) > 0 && isset( $errors[0]['text'] ) ) {
      return $this->sanitizeMessage( $errors[0]['text'] );
    }

    return __( 'The jobs could not be created.', 'wpml' );
  }


  private function sanitizeMessage( string $message ): string {
    $replacement_field = 'censured_field';
    $replacement_value = '*******';

    $patterns = [
      '/\\\\\"(?:accesskey|access_key|access-key)\\\\\":\\\\\"(?:[^\\\\\"]|\\\\.)*\\\\\"/i',
      '/\\\\\"(?:api_key|apiKey|API_KEY|token|secret|authorization)\\\\\":\\\\\"(?:[^\\\\\"]|\\\\.)*\\\\\"/i',
      '/(Bearer\s+)[A-Za-z0-9\-\._~\+\/]+=*/i',
    ];

    $replacements = [
      '\\"' . $replacement_field . '\\":\\"' . $replacement_value . '\\"',
      '\\"' . $replacement_field . '\\":\\"' . $replacement_value . '\\"',
      '$1' . $replacement_value,
    ];

    return (string) preg_replace( $patterns, $replacements, $message );
  }


}
