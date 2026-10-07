<?php

namespace WPML\Legacy\Component\Translation\Domain\Links;

use WPML\Core\Component\Translation\Domain\Links\AdjustLinksInterface;
use WPML\Core\Component\Translation\Domain\Links\Item;
use WPML\WordPress\Term;

use function WPML\Container\make;

class AdjustLinks implements AdjustLinksInterface {
  const TYPE_POST = 'post';
  const TYPE_TERM = 'term';


  public function adjust( Item $item, ?Item $triggerItem = null ) {
    if ( ! $item->getLanguageCode() ) {
      return;
    }

    $ICL_Pro_Translation = $GLOBALS['ICL_Pro_Translation'];

    if ( ! $ICL_Pro_Translation ) {
      return;
    }

    $preloaded = [];

    $post = get_post( $item->getId(), 'ARRAY_A' );
    if (
      is_array( $post ) &&
      $contentWithOriginalLink = $this->revertNameInTranslatedLinks( $post['post_content'], $triggerItem )
    ) {
      $preloaded['post_content'] = $contentWithOriginalLink;
      $preloaded['post_type'] = $post['post_type'];
    }

    if (
      is_array( $post ) &&
      $excerptWithOriginalLink = $this->revertNameInTranslatedLinks( $post['post_excerpt'], $triggerItem )
    ) {
      $preloaded['post_excerpt'] = $excerptWithOriginalLink;
      $preloaded['post_type'] = $post['post_type'];
    }

    $ICL_Pro_Translation->fix_links_to_translated_content(
      $item->getId(),
      $item->getLanguageCode(),
      $item->getType(),
      $preloaded
    );

    $this->adjustLinksInStringTranslations( $item, $ICL_Pro_Translation, $triggerItem );

    $postElement = make( \WPML_Translation_Element_Factory::class )->create_post( $item->getId() );
    do_action( 'wpml_pb_resave_post_translation', $postElement, false );
  }


  private function adjustLinksInStringTranslations(
    Item $item,
    \WPML_Pro_Translation $ICL_Pro_Translation,
    ?Item $triggerItem = null
  ) {
    if ( ! defined( 'WPML_ST_VERSION' ) || ! $item->getIdOriginal() ) {
      return;
    }

    $wpdb = $GLOBALS['wpdb'];
    $string_translations = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT
          translations.id,
          translations.string_id,
          translations.language,
          translations.value
        FROM `{$wpdb->prefix}icl_string_packages`as packages
        LEFT JOIN `{$wpdb->prefix}icl_strings` as strings
        ON packages.ID = strings.string_package_id
        LEFT JOIN `{$wpdb->prefix}icl_string_translations` as translations
        ON strings.id = translations.string_id
        WHERE packages.post_id = %d
        AND translations.language = %s",
        $item->getIdOriginal(),
        $item->getLanguageCode()
      )
    );
    foreach ( $string_translations as $string_translation ) {
      $contentWithOriginalLink = $this->revertNameInTranslatedLinks( $string_translation->value, $triggerItem );
      $ICL_Pro_Translation->fix_links_to_translated_content(
        $string_translation->id,
        $string_translation->language,
        'string',
        [
          'value' => $contentWithOriginalLink ?? $string_translation->value,
          'string_id' => $string_translation->string_id
        ]
      );
    }
  }


  private function revertNameInTranslatedLinks( string $content, ?Item $triggerItem = null ) {
    if ( ! $triggerItem ) {
      return null;
    }

    $triggerNameBefore = $triggerItem->getNameBefore();
    $triggerIdOriginal = $triggerItem->getIdOriginal();
    if (
      ! $triggerNameBefore
      || ! $triggerIdOriginal
    ) {
      return null;
    }

    switch ( $triggerItem->getType() ) {
      case self::TYPE_POST:
        $original = get_post( $triggerIdOriginal, 'ARRAY_A' );
        if ( is_array( $original ) ) {
          $originalName = $original['post_name'];
        }
        break;
      case self::TYPE_TERM:
        $original = Term::get( $triggerIdOriginal, '', 'ARRAY_A' );
        if ( is_array( $original ) ) {
          $originalName = $original['slug'];
        }
        break;
    }

    if ( ! isset( $originalName ) ) {
      return null;
    }

    return preg_replace_callback(
      '/href=("|\')(.*?)("|\')/',
      function( $matches ) use ( $originalName, $triggerNameBefore ) {
        $hrefUsingOriginal = str_replace(
          '/' . $triggerNameBefore .'/',
          '/' . $originalName . '/',
          $matches[2]
        );
        return 'href="' . $hrefUsingOriginal . '"';
      },
      $content
    );
  }


}
