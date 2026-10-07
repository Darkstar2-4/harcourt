<?php

namespace WPML\Core\Component\Translation\Application\Service\ResendUnsolvableJobs\DtoCollectionBuilder;

use WPML\Core\Component\Translation\Domain\Translation;


class TranslationGrouper {


  public function groupBySourceLanguage( array $translations ): array {
    $grouped = [];

    foreach ( $translations as $translation ) {
      $sourceLanguage = $translation->getSourceLanguageCode();

      if ( ! isset( $grouped[ $sourceLanguage ] ) ) {
        $grouped[ $sourceLanguage ] = [];
      }

      $grouped[ $sourceLanguage ][] = $translation;
    }

    return $grouped;
  }


}
