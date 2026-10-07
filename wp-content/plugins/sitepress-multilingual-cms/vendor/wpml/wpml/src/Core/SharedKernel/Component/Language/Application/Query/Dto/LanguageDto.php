<?php

namespace WPML\Core\SharedKernel\Component\Language\Application\Query\Dto;

class LanguageDto {

  private $code;

  private $englishName;

  private $nativeName;

  private $displayName;

  private $countryFlagUrl;

  private $isActivated;

  private $supportsAutomaticTranslations = null;

  private $defaultLocale;


  public function __construct(
    string $code,
    string $englishName,
    string $nativeName,
    string $defaultLocale,
    bool $isActivated = true
  ) {
    $this->code          = $code;
    $this->englishName   = $englishName;
    $this->nativeName    = $nativeName;
    $this->isActivated   = $isActivated;
    $this->displayName   = $englishName;
    $this->defaultLocale = $defaultLocale;
  }


  public function getCode(): string {
    return $this->code;
  }


  public function getEnglishName(): string {
    return $this->englishName;
  }


  public function getNativeName(): string {
    return $this->nativeName;
  }


  public function getCountryFlagUrl() {
    return $this->countryFlagUrl;
  }


  public function isActivated(): bool {
    return $this->isActivated;
  }


  public function setCountryFlagUrl( string $countryFlagUrl ) {
    $this->countryFlagUrl = $countryFlagUrl;
  }


  public function getDisplayName(): string {
    return $this->displayName;
  }


  public function setDisplayName( string $displayName ) {
    $this->displayName = $displayName;
  }


  public function doesSupportAutomaticTranslations() {
    return $this->supportsAutomaticTranslations;
  }


  public function getDefaultLocale(): string {
    return $this->defaultLocale;
  }


  public function setSupportsAutomaticTranslations( ?bool $supportsAutomaticTranslations = null ) {
    $this->supportsAutomaticTranslations = $supportsAutomaticTranslations;
  }


}
