<?php

namespace WPML\Legacy\Component\WordsToTranslate\Domain\Post;

use WPML\Core\Component\WordsToTranslate\Domain\Post\Post;
use WPML\Core\Component\WordsToTranslate\Domain\Post\Term\Term;
use WPML\Core\Component\WordsToTranslate\Domain\Post\Term\TermContent;
use WPML\Core\Component\WordsToTranslate\Domain\Post\Query\TranslationQueryInterface;

use WPML\Translation\TranslationElements\FieldCompression;

class TranslationQuery implements TranslationQueryInterface {

  private $jobs = [];

  private $trids = [];

  private $isTermTranslatable = [];

  private $termTranslationCache = [];

  private $_isTermRetranslationAllowed;


  public function getLastTranslatedOriginalContentForPost(
    $post,
    $lang,
    $fieldsToTranslate
  ) {
    $lastTranslatedContent = '';
    $job = $this->getJobByPost( $post, $lang );

    if ( ! $job ) {
      return $lastTranslatedContent;
    }

    $elements = $job->elements;

    if ( ! $elements ) {
      return $lastTranslatedContent;
    }

    foreach ( $elements as $element ) {
      if ( ! $element->field_translate ) {
        continue;
      }

      if (
        strpos( $element->field_type, 'package-string' ) !== 0 &&
        ! in_array( $element->field_type, $fieldsToTranslate, true )
      ) {
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


  private function getJobByPost( Post $post, string $lang ) {
    $jobKey = $post->getType() . $post->getId() . $lang;
    if ( isset( $this->jobs[ $jobKey ] ) ) {
      return $this->jobs[ $jobKey ];
    }

    $trid = $this->getJobTridByPost( $post, $lang );

    if ( ! $trid ) {
      $this->jobs[ $jobKey ] = null;
      return null;
    }

    global $wpdb;

    $jobId = $wpdb->get_var(
      $wpdb->prepare(
        "SELECT j.job_id
         FROM {$wpdb->prefix}icl_translate_job j
         JOIN {$wpdb->prefix}icl_translation_status s ON j.rid = s.rid
         JOIN {$wpdb->prefix}icl_translations t ON s.translation_id = t.translation_id
         WHERE t.trid = %d
         AND j.completed_date IS NOT NULL
         AND j.editor in ('ate', 'wpml')
         AND t.language_code = %s
         ORDER BY j.completed_date DESC
         LIMIT 1;",
         $trid,
         $lang
      )
    );

    if ( $jobId === null ) {
      $this->jobs[ $jobKey ] = null;
      return null;
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
      $this->jobs[ $jobKey ] = null;
      return null;
    }

    $job = new LegacyJob();
    $job->elements = $elements;

    $this->jobs[ $jobKey ] = $job;
    return $this->jobs[ $jobKey ];
  }


  private function getJobTridByPost( Post $post, string $lang ) {
    $jobKey = $post->getType() . $post->getId() . $lang;
    if ( isset( $this->trids[ $jobKey ] ) ) {
      return $this->trids[ $jobKey ];
    }

    $sitepress = $GLOBALS['sitepress'];
    $trid = $sitepress->get_element_trid( $post->getId(), 'post_' );

    if ( ! $trid ) {
      $this->trids[ $jobKey ] = null;
      return null;
    }

    $this->trids[ $jobKey ] = $trid;
    return $trid;
  }


  public function isTermTranslatable( Term $term, string $lang ) {
    if ( $this->isTermRetranslationAllowed() ) {
      return true;
    }

    $idTerm = $term->getId();
    $cache = $lang . $idTerm;

    if ( isset( $this->isTermTranslatable[ $cache ] ) ) {
      return $this->isTermTranslatable[ $cache ];
    }

    global $wpdb;
    $translation = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT
          {$wpdb->prefix}terms.term_id original_term_id,
          translation.trid translation_trid
        FROM {$wpdb->prefix}terms
        INNER JOIN {$wpdb->prefix}icl_translations original_translation
          ON original_translation.element_id = %d
        INNER JOIN {$wpdb->prefix}icl_translations translation
          ON translation.trid = original_translation.trid
          AND translation.language_code = '%s'
        WHERE {$wpdb->prefix}terms.term_id = %d LIMIT 1;",
        $idTerm,
        $lang,
        $idTerm
      )
    );

    return $this->isTermTranslatable[ $cache ] = count( $translation ) === 0;
  }


  public function getLastTranslatedOriginalContentForTermContent( Term $term, TermContent $termContent, string $langCode ) {
    $cacheKey = $term->getId() . $langCode;
    $isMeta = ! in_array( $termContent->getType(), [ 'description', 'name' ] );

    if ( isset( $this->termTranslationCache[ $cacheKey ] ) ) {
      if ( $isMeta ) {
        $metaData = $this->termTranslationCache[ $cacheKey ]['meta_data'] ?? [];

        if ( ! is_array( $metaData ) ) {
          return '';
        }

        if ( ! isset( $metaData[ $termContent->getType() ][0] ) ) {
          return '';
        }

        return $metaData[ $termContent->getType() ][0];
      } elseif ( isset( $this->termTranslationCache[ $cacheKey ][ $termContent->getType() ] ) ) {
        $value = $this->termTranslationCache[ $cacheKey ][ $termContent->getType() ];
        return is_string( $value ) ? $value : '';
      }
      return '';
    }

    $this->termTranslationCache[ $cacheKey ] = [];

    $wpdb = $GLOBALS['wpdb'];

    $translations = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT
          t.term_id,
          t.name,
          t.slug,
          tt.description,
          i.language_code
        FROM {$wpdb->prefix}terms AS t
        JOIN {$wpdb->prefix}term_taxonomy AS tt ON tt.term_id = t.term_id
        JOIN {$wpdb->prefix}icl_translations AS i ON i.element_id = tt.term_taxonomy_id
        INNER JOIN (
					SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_id = %d AND element_type LIKE 'tax_%' AND source_language_code IS NULL LIMIT 1
        ) lm on lm.trid = i.trid
        WHERE t.term_id != %d",
        $term->getId(),
        $term->getId()
      )
    );

    $sitepress = $GLOBALS['sitepress'];
		$setting_factory = $sitepress->core_tm()->settings_factory();

		foreach ( $translations as $translation ) {
			$meta_data = get_term_meta( $translation->term_id );

      if ( ! is_array( $meta_data ) ) {
        continue;
      }

			foreach ( $meta_data as $meta_key => $meta_data ) {
        if (
          in_array(
            $setting_factory->term_meta_setting( $meta_key )->status(),
            [ WPML_TRANSLATE_CUSTOM_FIELD, WPML_COPY_ONCE_CUSTOM_FIELD ],
            true
          )
        ) {
					$translation->meta_data[ $meta_key ] = $meta_data;
				}
			}
		}

    foreach ( $translations as $translation ) {
      $this->termTranslationCache[ $term->getId() . $translation->language_code ] = (array) $translation;
    }

    return $this->getLastTranslatedOriginalContentForTermContent( $term, $termContent, $langCode );
  }


  private function isTermRetranslationAllowed() {
    if ( $this->_isTermRetranslationAllowed === null ) {
      $sitepress = $GLOBALS['sitepress'];
      $this->_isTermRetranslationAllowed = ! $sitepress->get_setting( 'tm_block_retranslating_terms' );
    }

    return $this->_isTermRetranslationAllowed;
  }


}


class LegacyJob {

  public $elements = [];

}


class LegacyElement {

  public $field_translate = false;

  public $field_type = '';

  public $field_data = '';

}
