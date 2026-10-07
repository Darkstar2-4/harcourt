<?php

namespace WPML\Legacy\Component\WordsToTranslate\Domain\StringPackage;

use WPML\Core\Component\WordsToTranslate\Domain\Item;
use WPML\Core\Component\WordsToTranslate\Domain\StringPackage\Query\TranslationQueryInterface;

use WPML\Translation\TranslationElements\FieldCompression;

class TranslationQuery implements TranslationQueryInterface {


  public function getLastTranslatedOriginalContent( Item $stringPackage, string $lang ) {
    $sitepress = $GLOBALS['sitepress'];

    $package = new \WPML_Package( $stringPackage->getId() );

    $trid = $sitepress->get_element_trid(
      $package->ID,
      $package->get_translation_element_type()
    );

    if ( ! $trid ) {
        return '';
    }

    $wpdb = $GLOBALS['wpdb'];

    $jobId = $wpdb->get_var(
      $wpdb->prepare(
        "SELECT j.job_id
         FROM {$wpdb->prefix}icl_translate_job j
         JOIN {$wpdb->prefix}icl_translation_status s ON j.rid = s.rid
         JOIN {$wpdb->prefix}icl_translations t ON s.translation_id = t.translation_id
         WHERE t.trid = %d
         AND j.completed_date IS NOT NULL
         AND j.editor = 'ate'
         AND t.language_code = %s
         ORDER BY j.completed_date DESC
         LIMIT 1;",
         $trid,
         $lang
      )
    );

    if ( $jobId === null ) {
      return '';
    }

    $elements = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT translate.*
			  FROM {$wpdb->prefix}icl_translate translate
			  WHERE job_id = %d",
        $jobId
      )
    );

    if ( ! $elements ) {
      return '';
    }

    $lastTranslatedContent = '';

    foreach ( $elements as $element ) {
      if ( ! $element->field_translate ) {
        continue;
      }

      if (
        strpos( $element->field_type, 't_' ) === 0
        || strpos( $element->field_type, 'tdesc_' ) === 0
        || strpos( $element->field_type, 'tfield' ) === 0
      ) {
        continue;
      }

      $content = trim(
        FieldCompression::decompress( $element->field_data ) ?? ''
      );

      $lastTranslatedContent .= $content ? ' ' . $content : '';
    }

    return trim( $lastTranslatedContent );
  }


}
