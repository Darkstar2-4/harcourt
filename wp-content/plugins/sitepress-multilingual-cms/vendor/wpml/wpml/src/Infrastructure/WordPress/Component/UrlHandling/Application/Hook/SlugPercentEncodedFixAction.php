<?php

namespace WPML\Infrastructure\WordPress\Component\UrlHandling\Application\Hook;

class SlugPercentEncodedFixAction {

  private static $isProcessing = false;


  public function fixPercentInTranslationSlug(
    $title,
    $rawTitle = '',
    $context = 'save'
  ): ?string {
    if (
      self::$isProcessing
      || ! is_string( $rawTitle )
      || $context !== 'save'
      || $rawTitle === ''
      || strpos( $rawTitle, '%' ) === false
    ) {
      return $title;
    }

    $isTranslation = $this->isTranslationContext();

    if ( ! $isTranslation ) {
      return $title;
    }

    $decoded = rawurldecode( $rawTitle );
    if ( $decoded !== $rawTitle && mb_check_encoding( $decoded, 'UTF-8' ) ) {
      $rawTitle = $decoded;
    } else {
      $rawTitle = preg_replace( '/%(\d)/', '$1', $rawTitle ) ?? $rawTitle;
    }

    self::$isProcessing = true;
    $title              = sanitize_title( $rawTitle, '', $context );
    self::$isProcessing = false;

    return $title;
  }


  private function isTranslationContext(): bool {
    if ( defined( 'DOING_AJAX' ) && isset( $_POST['action'] ) ) {
      $ajaxActions = [ 'wpml_translation_dialog_save_job' ];
      $action = is_string( $_POST['action'] ) ? $_POST['action'] : '';
      if ( in_array( sanitize_text_field( wp_unslash( $action ) ), $ajaxActions, true ) ) {
        return true;
      }
    }

    if ( isset( $_POST['icl_post_language'] ) || isset( $_POST['to_lang'] ) ) {
      return true;
    }

    if ( isset( $_POST['job_id'] ) && isset( $_POST['fields'] ) ) {
      return true;
    }

    if ( function_exists( 'wpml_get_current_language' ) && function_exists( 'wpml_get_default_language' ) ) {
      $current = wpml_get_current_language();
      $default = wpml_get_default_language();
      if ( $current && $default && $current !== $default && isset( $_POST['trid'] ) ) {
        return true;
      }
    }

    return false;
  }


}
