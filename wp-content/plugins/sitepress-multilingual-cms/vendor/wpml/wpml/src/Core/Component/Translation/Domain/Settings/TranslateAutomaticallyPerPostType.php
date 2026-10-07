<?php

namespace WPML\Core\Component\Translation\Domain\Settings;

class TranslateAutomaticallyPerPostType {

  const AUTOMATIC_CONFIG = 'automatic-config';
  const OVERRIDE = 'automatic-override';

  private $automaticTranslationPerPostTypeConfig;


  public function __construct( array $automaticTranslationPerPostTypeConfig ) {
    $this->automaticTranslationPerPostTypeConfig = $automaticTranslationPerPostTypeConfig;
  }


  private function postTypesDisabledForAutomaticTranslation(): array {
    $fromConfig = $this->automaticTranslationPerPostTypeConfig[ self::AUTOMATIC_CONFIG ] ?? [];
    $override   = $this->automaticTranslationPerPostTypeConfig[ self::OVERRIDE ] ?? [];

    return array_filter(
      array_merge( $fromConfig, $override ),
      function ( $config ) {
        return $config === false;
      }
    );
  }


  public function hasAnyAutomaticTranslationDisabled(): bool {
    return count( $this->postTypesDisabledForAutomaticTranslation() ) > 0;
  }


}
