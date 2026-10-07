<?php

namespace WPML\Legacy\Component\ATE\Application\Service;

use WPML\Core\Component\ATE\Application\Service\Dto\Engine\FormalityLevelDto;
use WPML\Core\Component\ATE\Application\Service\Dto\Engine\FormalitySettingDto;
use WPML\Core\Component\ATE\Application\Service\Dto\EngineDto;
use WPML\Core\Component\ATE\Application\Service\Dto\UpdateEngine\FormalitySettingDto as UpdateEngineFormalitySettingDto;
use WPML\Core\Component\ATE\Application\Service\Dto\UpdateEngineDto;
use WPML\Core\Component\ATE\Application\Service\EngineServiceException;
use WPML\Core\Component\ATE\Application\Service\EnginesServiceInterface;
use WPML\Core\SharedKernel\Component\Language\Application\Query\LanguagesQueryInterface;
use WPML\TM\API\ATE\CachedLanguageMappings;
use WPML\TM\ATE\API\CachedAMSAPI;

class EnginesService implements EnginesServiceInterface {

  private $amsApi;

  private $languagesQuery;


  public function __construct( \WPML_TM_AMS_API $amsApi, LanguagesQueryInterface $languagesQuery ) {
    $this->amsApi         = $amsApi;
    $this->languagesQuery = $languagesQuery;
  }


  public function getList(): array {
    $engines              = $this->fetchEnginesData();
    $availableFormalities = $this->fetchAvailableFormalities();

    $result = [];
    foreach ( $engines as $engineData ) {
      $availableFormalitiesOfEngine = $availableFormalities[ $engineData['engine'] ]['languages'] ?? [];
      $result[]                     = $this->buildEngineFromArray( $engineData, $availableFormalitiesOfEngine );
    }

    return $result;
  }


  public function update( array $engines ) {
    $enginesData = [];
    foreach ( $engines as $engine ) {
      $engineRaw = [
        'engine'              => $engine->getEngine(),
        'enabled'             => $engine->isEnabled(),
        'formality_available' => $engine->isFormalityAvailable(),
      ];

      if ( $engine->isFormalityAvailable() && $engine->getFormalitySettings() ) {
        $formalitySettings = $engine->getFormalitySettings();

        $formalitySettingsData = [];
        foreach ( $formalitySettings as $formalitySetting ) {
          $formalitySettingsData[] = [
            'lang_code' => $formalitySetting->getLanguageCode(),
            'formality' => $formalitySetting->getCurrentLevel()->getValue(),
          ];
        }
        $engineRaw['formality_settings'] = [ 'languages' => $formalitySettingsData ];
      }

      $enginesData[] = $engineRaw;
    }

    try {
      $result = $this->amsApi->update_translation_engines( $enginesData );

      if ( is_wp_error( $result ) ) {
        throw new EngineServiceException();
      }

      if ( ! $result ) {
        throw new EngineServiceException();
      }
    } catch ( \Throwable $e ) {
      throw new EngineServiceException( 'The engines settings could not be saved' );
    }
  }


  public function flushCache() {
    CachedLanguageMappings::clearCache();
    CachedAMSAPI::clearCache();
  }


  private function fetchEnginesData(): array {
    $apiResult = $this->amsApi->get_translation_engines();

    if ( is_wp_error( $apiResult ) ) {
      throw new EngineServiceException(
        $apiResult->get_error_message()
      );
    }

    if ( ! is_array( $apiResult ) || ! isset( $apiResult['list'] ) ) {
      throw new EngineServiceException(
        __( 'Error fetching translation engines', 'wpml' )
      );
    }

    return $apiResult['list'];
  }


  private function fetchAvailableFormalities(): array {
    $apiResult = $this->amsApi->get_available_formalities();

    if ( is_wp_error( $apiResult ) ) {
      throw new EngineServiceException(
        $apiResult->get_error_message()
      );
    }

    if ( ! is_array( $apiResult ) || ! isset( $apiResult['engines'] ) ) {
      throw new EngineServiceException(
        __( 'Error fetching available formalities', 'wpml' )
      );
    }

    return $apiResult['engines'];
  }


  private function buildEngineFromArray( array $enginesData, array $availableFormalitiesOfEngine ): EngineDto {
    $engine             = $enginesData['engine'];
    $formalName         = $enginesData['formal_name'];
    $cost               = $enginesData['cost'] ?? 0;
    $enabled            = $enginesData['enabled'];
    $formalityAvailable = $enginesData['formality_available'];

    $engine = new EngineDto(
      $engine,
      $formalName,
      $cost,
      $enabled,
      $formalityAvailable
    );

    if ( $formalityAvailable ) {
      $currentFormalitySettings = $this->getEngineCurrentFormalitySettingsGroupedByLanguageCode( $enginesData );
      $formalitySettings        = [];

      foreach ( $this->languagesQuery->getSecondary() as $languageDto ) {
        $availableFormalitiesOfLanguage = $availableFormalitiesOfEngine[ $languageDto->getCode() ] ?? [];
        $availableLanguageLevels        = array_map(
          function ( string $levelValue ) {
            return new FormalityLevelDto( $levelValue );
          },
          $availableFormalitiesOfLanguage['available_formalities'] ?? []
        );

        $formalityLevel      = $currentFormalitySettings[ $languageDto->getCode() ] ?? FormalityLevelDto::default();
        $formalitySettings[] = new FormalitySettingDto(
          $languageDto->getCode(),
          $formalityLevel,
          $availableFormalitiesOfLanguage['formality_enabled'] ?? false,
          $availableLanguageLevels
        );
      }
      $engine->setFormalitySettings( $formalitySettings );
    }

    return $engine;
  }


  private function getEngineCurrentFormalitySettingsGroupedByLanguageCode( array $enginesData ): array {
    $formalitySettingsRaw = $enginesData['formality_settings']['languages'] ?? [];

    $result = [];
    foreach ( $formalitySettingsRaw as $row ) {
      $result[ $row['lang_code'] ] = new FormalityLevelDto( $row['formality'] );
    }

    return $result;
  }


}
