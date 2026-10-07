<?php

namespace WPML\Legacy\Component\WordsToTranslate\Domain;

use WPML\Translation\TranslationElements\FieldCompression;
use function WPML\Container\make;

trait JobPackageTrait {

  private $_wpmlElementTranslationPackage;


  private function getTranslatableFields( $package ) {
    if ( ! is_array( $package ) || ! isset( $package['contents'] ) ) {
      return [];
    }

    $translatableContent = [];

    foreach ( $package['contents'] as $type => $unit ) {
      if (
        ! isset( $unit['translate'] ) || ! $unit['translate']
        || ! isset( $unit['data'] ) || ! $unit['data']
        || ! isset( $unit['format'] )
      ) {
        continue;
      }

      $data = $unit['format'] === 'base64'
        ? FieldCompression::decompress( $unit['data'] ) ?? ''
        : $unit['data'];

      if ( preg_match( '/^(https?):\/\/[^\s\/$.?#].[^\s]*$/i', $data ) ) {
        continue;
      }

      $isTranslatable =
        ! \WPML_String_Functions::is_not_translatable( $data )
        && (bool) apply_filters( 'wpml_translation_job_post_meta_value_translated', 1, $type );

      $isTranslatable = apply_filters(
        'wpml_tm_job_field_is_translatable',
        $isTranslatable,
        [
          'field_translate' => $unit['translate'],
          'field_type' => $type,
          'field_data' => $data,
          'field_format' => $unit['format']
        ],
        $data
      );

      if ( ! $isTranslatable ) {
        continue;
      }

      $translatableContent[ (string) $type ] = trim( $data );
    }

    return $translatableContent;
  }


  private function wpmlElementTranslationPackage() {
    if ( ! $this->_wpmlElementTranslationPackage ) {
      $this->_wpmlElementTranslationPackage = make( \WPML_Element_Translation_Package::class );
    }

    return $this->_wpmlElementTranslationPackage;
  }


}
