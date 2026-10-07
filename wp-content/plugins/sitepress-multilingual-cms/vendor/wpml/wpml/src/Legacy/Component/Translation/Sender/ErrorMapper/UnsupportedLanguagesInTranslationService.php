<?php

namespace WPML\Legacy\Component\Translation\Sender\ErrorMapper;

use WPML\Core\SharedKernel\Component\Language\Application\Query\Dto\LanguageDto;
use WPML\Core\SharedKernel\Component\Language\Application\Query\LanguagesQueryInterface;

class UnsupportedLanguagesInTranslationService implements StrategyInterface {

  private $languageQuery;


  public function __construct( LanguagesQueryInterface $languageQuery ) {
    $this->languageQuery = $languageQuery;
  }


  public function map( array $errors ) {
    $unsupportedLanguages = [];
    $pattern              = '/This service does not support the following iso-codes: (.*)/';
    foreach ( $errors as $error ) {
      if ( preg_match( $pattern, $error['text'] ?? '', $matches ) ) {
        $unsupportedLanguages = array_merge( $unsupportedLanguages, explode( ',', $matches[1] ) );
      }
    }

    if ( $unsupportedLanguages ) {
      $unsupportedLanguages = $this->getLanguageNames( $unsupportedLanguages );

      return sprintf(
        __( "The selected translation service doesn't support the following languages: %s", 'wpml' ),
        implode( ', ', $unsupportedLanguages )
      );
    }

    return null;
  }


  private function getLanguageNames( array $languageCodes ): array {
    $languages = $this->getActiveLanguagesGroupedByCode();

    return array_map(
      function ( string $languageCode ) use ( $languages ) {
        return $languages[ $languageCode ] ?? $languageCode;
      },
      $languageCodes
    );
  }


  private function getActiveLanguagesGroupedByCode(): array {
    $languages = $this->languageQuery->getActive();

    $result = array_reduce(
      $languages,
      function ( $carry, LanguageDto $language ) {
        $carry[ $language->getCode() ] = $language->getDisplayName();

        return $carry;
      },
      []
    );

    return $result;
  }


}
