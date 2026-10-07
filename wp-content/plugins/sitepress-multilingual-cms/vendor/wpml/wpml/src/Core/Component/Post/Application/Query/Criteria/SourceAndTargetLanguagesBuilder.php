<?php

namespace WPML\Core\Component\Post\Application\Query\Criteria;

use WPML\Core\SharedKernel\Component\Language\Application\Query\LanguagesQueryInterface;
use WPML\PHP\Exception\InvalidArgumentException;

final class SourceAndTargetLanguagesBuilder {

  private $languagesQuery;


  public function __construct( LanguagesQueryInterface $languagesQuery ) {
    $this->languagesQuery = $languagesQuery;
  }


  public function build(
    ?string $sourceLanguageCode = null,
    ?array $targetLanguageCodes = null
  ): SourceAndTargetLanguages {
    $activeCodes = array_map(
      function ( $languageDto ) {
        return $languageDto->getCode();
      },
      $this->languagesQuery->getActive()
    );

    if ( $sourceLanguageCode !== null && ! in_array( $sourceLanguageCode, $activeCodes, true ) ) {
      throw new InvalidArgumentException( 'Invalid source language code.' );
    }

    $source = $sourceLanguageCode ?? $this->languagesQuery->getDefaultCode();

    if ( $targetLanguageCodes === null ) {
      $targets = array_map(
        function ( $languageDto ) {
          return $languageDto->getCode();
        },
        $this->languagesQuery->getSecondary( true, $source )
      );
    } else {
      foreach ( $targetLanguageCodes as $code ) {
        if ( ! in_array( $code, $activeCodes, true ) ) {
          throw new InvalidArgumentException( 'Invalid target language code.' );
        }
      }

      $targets = array_values(
        array_filter(
          $targetLanguageCodes,
          function ( $code ) use ( $source ) {
            return $code !== $source;
          }
        )
      );

      if ( empty( $targets ) ) {
        $targets = array_map(
          function ( $languageDto ) {
            return $languageDto->getCode();
          },
          $this->languagesQuery->getSecondary( true, $source )
        );
      }
    }

    return new SourceAndTargetLanguages( $source, $targets );
  }


}
