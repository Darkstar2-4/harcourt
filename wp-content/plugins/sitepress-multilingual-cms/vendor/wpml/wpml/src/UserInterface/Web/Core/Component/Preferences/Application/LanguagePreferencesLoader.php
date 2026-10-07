<?php

namespace WPML\UserInterface\Web\Core\Component\Preferences\Application;

use WPML\Core\Port\PluginInterface;
use WPML\Core\SharedKernel\Component\Language\Application\Query\Dto\LanguageDto;
use WPML\Core\SharedKernel\Component\Language\Application\Query\LanguagesQueryInterface;

class LanguagePreferencesLoader {

  private $languagesQuery;

  private $pluginInterface;


  public function __construct( LanguagesQueryInterface $languagesQuery, PluginInterface $pluginInterface ) {
    $this->languagesQuery = $languagesQuery;
    $this->pluginInterface = $pluginInterface;
  }


  private function getLanguages(): array {

    return array_reduce(
      $this->languagesQuery->getActive(),
      function ( array $carry, LanguageDto $language ) {
        $carry[ $language->getCode() ] = [
          'code'                             => $language->getCode(),
          'name'                             => $language->getDisplayName(),
          'flagUrl'                          => $language->getCountryFlagUrl(),
          'homeUrl'                         =>  $this->pluginInterface->getLanguageHomeUrl( $language->getCode() ),
          'doesSupportAutomaticTranslations' => $language->doesSupportAutomaticTranslations(),
        ];

        return $carry;
      },
      []
    );
  }


  private function getLanguagesTo(): array {
    return array_map(
      function ( LanguageDto $language ) {
        return $language->getCode();
      },
      $this->languagesQuery->getSecondary( true )
    );
  }


  public function get(): array {
    return [
      'languages'         => $this->getLanguages(),
      'languagesSettings' => [
        'from'    => $this->languagesQuery->getCurrentLanguageCode(),
        'to'      => $this->getLanguagesTo(),
        'default' => $this->languagesQuery->getDefaultCode(),
      ]
    ];
  }


}
