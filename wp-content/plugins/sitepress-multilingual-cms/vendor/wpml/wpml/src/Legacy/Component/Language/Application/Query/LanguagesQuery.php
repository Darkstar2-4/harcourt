<?php

namespace WPML\Legacy\Component\Language\Application\Query;

use WPML\Core\SharedKernel\Component\Language\Application\Query\Dto\LanguageDto;
use WPML\Core\SharedKernel\Component\Language\Application\Query\LanguagesQueryInterface;

class LanguagesQuery implements LanguagesQueryInterface {

  const DEFAULT_LANGUAGE_CODE = 'en';

  private $sitepress;


  public function __construct( $sitepress ) {
    $this->sitepress = $sitepress;
  }


  public function getDefaultCode(): string {
    return (string) $this->sitepress->get_default_language() ?: static::DEFAULT_LANGUAGE_CODE;
  }


  public function getCurrentLanguageCode(): string {
    return (string) $this->sitepress->get_current_language() ?: $this->getDefaultCode();
  }


  public function getDefault(): LanguageDto {
    $details = $this->sitepress->get_language_details(
      $this->getDefaultCode()
    );

    return $this->buildLanguage( $details );
  }


  public function getActive(): array {
    $result = [];
    $languages = $this->sitepress->get_active_languages();

    foreach ( $languages as $language ) {
      $result[] = $this->buildLanguage( $language );
    }

    return $result;
  }


  public function getSecondary( bool $withRespectToCurrentLang = false, $currentLang = null ): array {
    $defaultCode = $this->getDefaultCode();

    if ( $withRespectToCurrentLang ) {
      $defaultCode = $currentLang ?: $this->getCurrentLanguageCode();
    }

    return array_values(
      array_filter(
        $this->getActive(),
        function ( LanguageDto $language ) use ( $defaultCode ) {
          return $language->getCode() !== $defaultCode;
        }
      )
    );
  }


  private function buildLanguage( array $details ): LanguageDto {
    $result = new LanguageDto(
      $details['code'],
      $details['english_name'],
      $details['native_name'],
      $details['default_locale']
    );

    $flagUrl = $this->sitepress->get_flag_url( $details['code'] );

    $result->setDisplayName( $details['display_name'] );
    $result->setCountryFlagUrl( $flagUrl );

    return $result;
  }


}
